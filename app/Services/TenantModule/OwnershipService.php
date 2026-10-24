<?php

namespace App\Services\TenantModule;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipFifoResult;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipAcquisitionCreate;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipCandidateFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipListFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipLotFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipMovementFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipItemDetail;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipItemPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipLotPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipMovementPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipPiecePage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipReceiptPage;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\DataObjects\RequestObjects\InventoryListFilter;
use App\DataObjects\ResponseObjects\InventoryItemListPage;
use App\Exceptions\InvalidTenantRequest;
use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\AcquisitionLot;
use App\Models\OwnershipModule\OwnedItem;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\CollateralItemService;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Ownership\AcquisitionService;
use App\Services\TenantModule\Ownership\BalanceService;
use App\Services\TenantModule\Ownership\FifoService;
use App\Services\TenantModule\Ownership\LockService;
use App\Services\TenantModule\Ownership\MovementService;
use App\Services\TenantModule\Ownership\QuantityService;
use App\Services\TenantModule\Ownership\ReadService;
use App\Services\TenantModule\Ownership\ReceiptLinkService;
use App\Services\TenantModule\Ownership\ReturnValidationService;
use App\Services\TenantModule\Ownership\ReturnService;
use App\Support\InventoryQuantity;
use App\Support\OwnershipQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OwnershipService extends BaseTenantService
{
    public function __construct(
        private OwnershipRepository $repository,
        private InventoryService $inventoryService,
        private CollateralItemService $collateralItemService,
        private ExpirationService $expirationService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $tableIdGenerationService,
        // Declare stable module collaborators explicitly for container wiring and isolated tests.
        private LockService $lockService,
        private FifoService $fifoService,
        private QuantityService $quantityService,
        private AcquisitionService $acquisitionService,
        private ReadService $readService,
        private ReceiptLinkService $receiptLinkService,
        private BalanceService $balanceService,
        private MovementService $movementService,
        private ReturnValidationService $returnValidationService,
        private ReturnService $returnService,
    ) {
    }

    public function prelockItems(array $inventoryItemCodes): void
    {
        // Lock the complete set before any caller enters downstream custody or quantity work.
        $this->lockService->items($inventoryItemCodes);
    }

    public function allocateFifo(string $inventoryItemCode, mixed $quantity, array $serialNumbers = []): OwnershipFifoResult
    {
        // Shared allocator retains caller locks and leaves business effects to the owning operation.
        return $this->fifoService->allocate($inventoryItemCode, $quantity, $serialNumbers);
    }

    public function consumeSaleReservation(string $code, string $lotCode, string|int|float $quantity, string $sourceCode,
        array $serializedItemCodes = []): OwnershipResult
    {
        // Consume only the selected sale's remaining commitment and its exact receipt-backed quantities.
        return $this->quantityService
            ->applyOwnershipQuantityAction($code, $lotCode, $quantity, $sourceCode, $serializedItemCodes, 'consume');
    }

    public function acquirePurchaseLine(string $lineCode, ?string $key = null): OwnershipResult
    {
        // A settled source can be owned before receipt; later linking never creates another acquisition.
        return $this->acquisitionService->acquirePaidPurchaseLine($lineCode, $key);
    }

    public function itemPage(OwnershipListFilter $filter): OwnershipItemPage
    {
        // Expose bounded API summaries while legacy internal caller adapters remain separate.
        return $this->readService->itemPage($filter);
    }

    public function itemDetail(string $code): OwnershipItemDetail
    {
        return $this->readService->detail($code);
    }

    public function lotPage(string $code, OwnershipLotFilter $filter): OwnershipLotPage
    {
        return $this->readService->lotPage($code, $filter);
    }

    public function movementPage(string $code, OwnershipMovementFilter $filter): OwnershipMovementPage
    {
        return $this->readService->movementPage($code, $filter);
    }

    public function receiptLinkCandidates(string $code, string $lotCode, OwnershipCandidateFilter $filter): OwnershipReceiptPage|OwnershipPiecePage
    {
        return $this->readService->candidates($code, $lotCode, $filter);
    }

    public function linkPurchaseReceipt(string $receiptCode): OwnershipResult
    {
        // Trusted immediate receipt uses actual persisted source and piece provenance.
        return $this->receiptLinkService->linkPurchaseReceipt($receiptCode);
    }

    public function linkReceipt(string $code, string $lotCode, OwnershipReceiptLinkRequest $request, bool $publicReplay = false): OwnershipResult
    {
        // Real receipt linking is a dependency of FIFO's lot/piece eligibility, not a fake test setup.
        return $this->receiptLinkService->link($code, $lotCode, $request, $publicReplay);
    }

    public function list(?string $search = null): array
    {
        // Shape tenant-owned items with their balances, lots, and current Inventory location.
        $tenantId = $this->resolveCurrentTenantId();
        return $this->repository->ownedItems($tenantId, $search)->map(
            fn (OwnedItem $ownedItem): array => $this->ownedItemResource($tenantId, $ownedItem)
        )->all();
    }

    public function detailByCode(string $ownedItemCode): array
    {
        // Load a tenant-owned aggregate using its public business code.
        $tenantId = $this->resolveCurrentTenantId();
        $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode);
        abort_if($ownedItem === null, 404);
        return $this->ownedItemResource($tenantId, $ownedItem);
    }

    public function availableQuantity(string $ownedItemCode, ?string $lotCode = null): float
    {
        // Expose the current available balance for future module service contracts.
        $tenantId = $this->resolveCurrentTenantId();
        $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode);
        if ($ownedItem === null) {
            throw ValidationException::withMessages(['owned_item_code' => [__('ownership.not_found')]]);
        }
        if ($lotCode !== null) {
            $lot = $this->repository->lotByCode($tenantId, $ownedItem->id, $lotCode);
            if ($lot === null) {
                throw ValidationException::withMessages(['acquisition_lot_code' => [__('ownership.lot_not_found')]]);
            }
            $balances = $this->repository->lotBalances($tenantId, $ownedItem->id, $lot->id);
        } else {
            $balances = $this->repository->movementBalances($tenantId, $ownedItem->id);
        }
        return round($balances['owned'] - $balances['pledged'] - ($balances['reserved'] ?? 0), 3);
    }

    public function fifoLots(string $ownedItemCode): array
    {
        // Expose eligible acquisition layers to Sales in stable FIFO order.
        $tenantId = $this->resolveCurrentTenantId();
        $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode);
        if ($ownedItem === null) {
            throw ValidationException::withMessages(['owned_item_code' => [__('ownership.not_found')]]);
        }
        return $ownedItem->acquisitionLots->sortBy([['acquired_at', 'asc'], ['id', 'asc']])->map(function (AcquisitionLot $lot) use ($tenantId, $ownedItem): array {
            $balance = $this->repository->lotBalances($tenantId, (int) $ownedItem->id, (int) $lot->id);
            return [
                'id' => (int) $lot->id,
                'code' => $lot->code,
                'acquired_at' => $lot->acquired_at?->toDateString(),
                'available_quantity' => max(0, round($balance['owned'] - $balance['pledged'] - ($balance['reserved'] ?? 0), 3)),
                'unit_cost_basis' => (string) $lot->unit_cost_basis,
                'currency_code' => $lot->currency_code,
                'inventory_item_code' => $ownedItem->inventoryItem->code,
            ];
        })->values()->all();
    }

    public function reserveSale(string $ownedItemCode, string $lotCode, string|int|float $quantity, string $sourceCode, array $serializedItemCodes = []): OwnershipResult
    {
        // Every new reservation creates a real source-bound commitment, including fungible quantities.
        return $this->quantityService
            ->applyOwnershipQuantityAction($ownedItemCode, $lotCode, $quantity, $sourceCode, $serializedItemCodes, 'reserve');
    }

    public function reserveLegacySale(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode): array
    {
        // Keep confirmed Sale quantity in the ownership ledger without reducing ownership.
        return $this->changeQuantity('ownership.sale.reserve', $ownedItemCode, $lotCode, $quantity, 'SaleOrderLine', $sourceCode,
            null, 'SALE_RESERVE', 0, 0, 1);
    }

    public function releaseSaleReservation(string $ownedItemCode, string $lotCode, string|int|float $quantity, string $sourceCode, array $serializedItemCodes = []): OwnershipResult
    {
        // Release only this sale's remaining source commitment.
        return $this->quantityService
            ->applyOwnershipQuantityAction($ownedItemCode, $lotCode, $quantity, $sourceCode, $serializedItemCodes, 'release');
    }

    public function releaseLegacySaleReservation(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode): array
    {
        // Release undelivered Sale quantity when a line is fulfilled or cancelled.
        return $this->changeQuantity('ownership.sale.release', $ownedItemCode, $lotCode, $quantity, 'SaleOrderLine', $sourceCode,
            null, 'SALE_RELEASE', 0, 0, -1);
    }

    public function movementsByCode(string $ownedItemCode): array
    {
        // Resolve the public code before reading the tenant-safe ledger history.
        $tenantId = $this->resolveCurrentTenantId();
        $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode);
        abort_if($ownedItem === null, 404);
        return $this->repository->movements($tenantId, (int) $ownedItem->id)->map(fn ($movement): array => [
            'code' => $movement->code,
            'acquisition_lot_code' => $movement->acquisitionLot?->code,
            'movement_type' => $movement->movement_type,
            'quantity' => $movement->quantity,
            'owned_delta' => $movement->owned_delta,
            'pledged_delta' => $movement->pledged_delta,
            'source_module' => $movement->source_module,
            'source_type' => $movement->source_type,
            'source_code' => $movement->source_code,
            'occurred_at' => $movement->occurred_at?->toIso8601String(),
        ])->all();
    }

    public function inventoryOptions(InventoryListFilter $filter): InventoryItemListPage
    {
        // Reuse bounded summaries instead of loading every identity for an acquisition picker.
        return $this->inventoryService->itemSummaryPage($filter);
    }

    public function manualAcquisition(OwnershipAcquisitionCreate $request, ?string $idempotencyKey): array
    {
        // Use a stable request hash so client retries cannot create duplicate lots.
        return $this->runIdempotent('ownership.manual_acquisition', $idempotencyKey, $request->toArray(),
            fn (int $tenantId, ?int $recordId): array => $this->recordAcquisition($tenantId, $request, 'OWNERSHIP', 'MANUAL_ACQUISITION', null, $recordId), 201);
    }

    public function acquireFromSource(
        OwnershipAcquisitionCreate $request,
        string $sourceModule,
        string $sourceType,
        string $sourceCode,
        ?string $idempotencyKey = null,
    ): array {
        // Expose an internal entry point for future Purchasing and Pawn orchestration.
        if (! in_array($sourceModule, ['PURCHASING', 'PAWN'], true)) {
            throw ValidationException::withMessages(['source_module' => [__('ownership.unsupported_source')]]);
        }
        $expectedSourceTypes = ['PURCHASING' => ['PurchaseReceiptLine'], 'PAWN' => ['PawnCollateralItem']];
        if (! in_array($sourceType, $expectedSourceTypes[$sourceModule], true)) {
            throw ValidationException::withMessages(['source_type' => [__('ownership.unsupported_source')]]);
        }
        return $this->runIdempotent('ownership.acquire.'.strtolower($sourceModule), $idempotencyKey,
            [...$request->toArray(), 'source_type' => $sourceType, 'source_code' => $sourceCode],
            fn (int $tenantId, ?int $recordId): array => $this->recordAcquisition($tenantId, $request, $sourceModule, $sourceType, $sourceCode, $recordId), 201);
    }

    public function reservePledge(string $ownedItemCode, string $lotCode, string|int|float $quantity, string $sourceType, string $sourceCode,
        ?string $idempotencyKey = null, ?array $serializedItemCodes = null, ?OwnershipPledgeSource $sourceContext = null): OwnershipResult
    {
        // Only a trusted resolved collateral source can create a pledge; no code-only acquisition bypass.
        if ($sourceType !== 'BusinessLoanCollateral' || $sourceContext === null) throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        return $this->quantityService->applyOwnershipQuantityAction($ownedItemCode, $lotCode, $quantity, $sourceCode,
            $serializedItemCodes ?? [], 'pledge', $sourceContext, $idempotencyKey);
    }

    public function releasePledge(string $ownedItemCode, string $lotCode, string|int|float $quantity, string $sourceType,
        string $sourceCode, ?string $idempotencyKey = null, array $serializedItemCodes = []): OwnershipResult
    {
        // Existing source-bound commitment supplies authority; this changes no lender custody or money.
        if ($sourceType !== 'BusinessLoanCollateral') throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        return $this->quantityService->applyOwnershipQuantityAction($ownedItemCode, $lotCode, $quantity, $sourceCode,
            $serializedItemCodes, 'unpledge', idempotencyKey: $idempotencyKey);
    }

    public function reduceForSale(string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, ?string $idempotencyKey = null): array
    {
        // Reduce only unpledged ownership when a future Sales workflow records a sale.
        return $this->changeQuantity('ownership.sale', $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode,
            $idempotencyKey, 'SALE', -1, 0);
    }

    public function restoreForSaleReturn(string $ownedItemCode, string $lotCode, string|int|float $quantity,
        string $sourceCode, ?array $serializedItemCodes = null): OwnershipResult
    {
        // Existing internal caller restores its original allocation; no public return workflow is enabled.
        return $this->returnService->applyOriginalLotReturn($ownedItemCode, $lotCode, $quantity,
            $sourceCode, 'SaleReturnLine', $serializedItemCodes);
    }

    public function reduceForPurchaseReturn(string $ownedItemCode, string $lotCode, string|int|float $quantity,
        string $sourceCode, ?string $idempotencyKey = null): OwnershipResult
    {
        return $this->returnService->applyOriginalLotReturn($ownedItemCode, $lotCode, $quantity,
            $sourceCode, 'PurchaseReturnLine', key: $idempotencyKey);
    }

    public function reduceForfeiture(string $ownedItemCode, string $lotCode, string|int|float $quantity, string $sourceType,
        string $sourceCode, bool $fromPledge = true, ?string $idempotencyKey = null, array $serializedItemCodes = []): OwnershipResult
    {
        // Forfeiture requires this source's pledge, including the fully pledged final units.
        if (! $fromPledge || $sourceType !== 'BusinessLoanCollateral') throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        return $this->quantityService->applyOwnershipQuantityAction($ownedItemCode, $lotCode, $quantity, $sourceCode,
            $serializedItemCodes, 'forfeit', idempotencyKey: $idempotencyKey);
    }

    private function recordAcquisition(int $tenantId, OwnershipAcquisitionCreate $request, string $sourceModule, string $sourceType, ?string $sourceCode, ?int $idempotencyRecordId): array
    {
        // Existing internal/manual callers retain their response contract until OWN-30 activation.
        return DB::transaction(function () use ($tenantId, $request, $sourceModule, $sourceType, $sourceCode, $idempotencyRecordId): array {
            $item = $this->inventoryService->findForOwnershipByCode($request->inventoryItemCode);
            if ((int) $item->tenant_id !== $tenantId) throw new InvalidTenantRequest(__('ownership.inventory_unavailable'));
            $this->validateQuantity($item, $request->quantity);
            $owner = $this->resolveInternalAcquisitionOwner($tenantId, $item, $sourceModule, $sourceType, $sourceCode);
            $lot = $this->createInternalAcquisitionLot($tenantId, $owner, $request, $sourceModule, $sourceType, $sourceCode);
            $this->appendInternalAcquisitionMovement($owner, $lot, $request, $sourceModule, $sourceType, $sourceCode, $idempotencyRecordId);
            return $this->ownedItemResource($tenantId, $this->repository->ownedItem($tenantId, (int) $owner->id));
        });
    }

    private function resolveInternalAcquisitionOwner(int $tenantId, InventoryItem $inventoryItem, string $sourceModule,
        string $sourceType, ?string $sourceCode): OwnedItem
    {
        // Resolve the current aggregate before locking its canonical source lot.
        $ownedItem = $this->repository->ownedItemForInventory($tenantId, $inventoryItem->id, true);
        if ($sourceCode !== null && $this->repository->findBySourceCode($tenantId, $sourceModule, $sourceType, $sourceCode, true) !== null) {
            throw ValidationException::withMessages(['source_code' => [__('ownership.source_already_recorded')]]);
        }

        if ($inventoryItem->tracking_mode === 'UNIQUE' && $ownedItem !== null) {
            // UNIQUE reacquisition must observe current rows rather than an earlier caller snapshot.
            if (OwnershipQuantity::compare($this->repository->lockedOwnedQuantity($tenantId, (int) $ownedItem->id), '0.000') > 0) {
                throw ValidationException::withMessages(['inventory_item_code' => [__('ownership.unique_already_owned')]]);
            }
        }
        if ($ownedItem === null) {
            $ownedItem = $this->repository->createOwnedItem($tenantId, [
                'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'owned_items', CarbonImmutable::now()),
                'inventory_item_id' => $inventoryItem->id,
                'catalog_item_id' => $inventoryItem->catalog_item_id,
                'lifecycle_status' => 'ACTIVE',
                'created_by' => Auth::id(),
            ]);
        }
        return $ownedItem;
    }

    private function createInternalAcquisitionLot(int $tenantId, OwnedItem $ownedItem, OwnershipAcquisitionCreate $request,
        string $sourceModule, string $sourceType, ?string $sourceCode): AcquisitionLot
    {
        // Persist immutable snapshots independently of later receipt or quantity changes.
        return $this->repository->createLot($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'acquisition_lots', CarbonImmutable::now()),
            'owned_item_id' => $ownedItem->id,
            'source_module' => $sourceModule,
            'source_type' => $sourceType,
            'source_code' => $sourceCode,
            'acquired_at' => $request->acquiredAt,
            'acquired_quantity' => $request->quantity,
            'unit_cost_basis' => $request->unitCostBasis,
            'estimated_unit_value' => $request->estimatedUnitValue,
            'currency_code' => strtoupper($request->currencyCode),
            'description' => $request->description,
            'created_by' => Auth::id(),
        ]);
    }

    private function appendInternalAcquisitionMovement(OwnedItem $ownedItem, AcquisitionLot $lot, OwnershipAcquisitionCreate $request,
        string $sourceModule, string $sourceType, ?string $sourceCode, ?int $idempotencyRecordId): void
    {
        // The shared append enforces exact deltas and aggregate/monthly-publication exclusion.
        $this->movementService->appendOwnershipMovement([
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'ownership_movements', CarbonImmutable::now()),
            'owned_item_id' => $ownedItem->id,
            'acquisition_lot_id' => $lot->id,
            'movement_type' => 'ACQUISITION',
            'quantity' => $request->quantity,
            'owned_delta' => $request->quantity,
            'pledged_delta' => 0,
            'source_module' => $sourceModule,
            'source_type' => $sourceType,
            'source_code' => $sourceCode,
            'idempotency_record_id' => $idempotencyRecordId,
            'created_by' => Auth::id(),
            'occurred_at' => now(),
        ]);
    }

    private function changeQuantity(string $operation, string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, ?string $idempotencyKey, string $movementType, int $ownedSign, int $pledgedSign, int $reservedSign = 0): array
    {
        // Apply a lot-specific ownership change atomically after validating its available balance.
        $expectedSourceType = match ($movementType) {
            'PLEDGE', 'RELEASE', 'FORFEITURE' => 'BusinessLoanCollateral',
            'SALE' => 'SaleLine',
            'SALE_RESERVE', 'SALE_RELEASE' => 'SaleOrderLine',
            'SALE_RETURN' => 'SaleReturnLine',
            'PURCHASE_RETURN' => 'PurchaseReturnLine',
            default => null,
        };
        if ($expectedSourceType === null || $sourceType !== $expectedSourceType || trim($sourceCode) === '') {
            throw ValidationException::withMessages(['source_type' => [__('ownership.unsupported_source')]]);
        }
        return $this->runIdempotent($operation, $idempotencyKey,
            compact('ownedItemCode', 'lotCode', 'quantity', 'sourceType', 'sourceCode'),
            function (int $tenantId, ?int $recordId) use ($ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode, $movementType, $ownedSign, $pledgedSign, $reservedSign): array {
                return DB::transaction(function () use ($tenantId, $recordId, $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode, $movementType, $ownedSign, $pledgedSign, $reservedSign): array {
                    // Resolve internal return authority before Inventory/Ownership locks; public gates remain unchanged.
                    $returnSource = in_array($sourceType, ['PurchaseReturnLine', 'SaleReturnLine'], true)
                        ? $this->returnValidationService->resolveReturnSource($sourceType, $sourceCode) : null;
                    // Resolve business codes into internal ledger keys while holding tenant-scoped locks.
                    $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode);
                    if ($ownedItem === null) {
                        throw ValidationException::withMessages(['owned_item_code' => [__('ownership.not_found')]]);
                    }
                    // Compatibility mutations acquire Inventory before Ownership, like current commands.
                    $this->inventoryService->findForOwnershipById((int) $ownedItem->inventory_item_id);
                    $ownedItem = $this->repository->lockAggregate($tenantId, (int) $ownedItem->id);
                    $lot = $this->repository->lotByCode($tenantId, (int) $ownedItem->id, $lotCode, true);
                    if ($lot === null) {
                        throw ValidationException::withMessages(['acquisition_lot_code' => [__('ownership.lot_not_found')]]);
                    }
                    if (! is_finite($quantity) || $quantity <= 0) {
                        throw ValidationException::withMessages(['quantity' => [__('ownership.quantity_positive')]]);
                    }
                    if ($returnSource !== null) {
                        // Original source/lot/pieces and remaining reversal quantity are authoritative.
                        $validatedReturnSelection = $this->returnValidationService->validateReturnEligibility($returnSource, $ownedItem, $lot, $sourceType, $quantity, $ownedItem->inventoryItem->tracking_mode);
                    }
                    // Use authoritative current reads even when an outer caller established an older snapshot.
                    $scaled = $this->balanceService->locked((int) $ownedItem->id, [$lot])[$lot->id];
                    $balances = array_map(fn (int $value): float => $value / 1000, $scaled);
                    $available = $balances['owned'] - $balances['pledged'] - ($balances['reserved'] ?? 0);
                    if (($ownedSign < 0 && $quantity > $available) || ($pledgedSign < 0 && $quantity > $balances['pledged'])
                        || ($pledgedSign > 0 && $quantity > $available)
                        || ($reservedSign < 0 && $quantity > ($balances['reserved'] ?? 0))) {
                        throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
                    }
                    if ($sourceType === 'PurchaseReturnLine') {
                        // Return only source-validated free backing; keep original receipt links and membership history.
                        $this->quantityService->consumePurchaseReturnReceiptBacking($ownedItem, $lot, $validatedReturnSelection);
                    }
                    $this->movementService->appendOwnershipMovement([
                        'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'ownership_movements', CarbonImmutable::now()),
                        'owned_item_id' => $ownedItem->id,
                        'acquisition_lot_id' => $lot->id,
                        'movement_type' => $movementType,
                        'quantity' => $quantity,
                        'owned_delta' => $quantity * $ownedSign,
                        'pledged_delta' => $quantity * $pledgedSign,
                        'reserved_delta' => $quantity * $reservedSign,
                        'source_module' => match ($movementType) {
                            'PLEDGE', 'RELEASE', 'FORFEITURE' => 'BUSINESS_LOAN',
                            'SALE', 'SALE_RESERVE', 'SALE_RELEASE', 'SALE_RETURN' => 'SALES',
                            'PURCHASE_RETURN' => 'PURCHASING',
                            default => 'OWNERSHIP',
                        },
                        'source_type' => $sourceType,
                        'source_code' => $sourceCode,
                        'idempotency_record_id' => $recordId,
                        'created_by' => Auth::id(),
                        'occurred_at' => now(),
                    ]);
                    return $this->ownedItemResource($tenantId, $this->repository->ownedItem($tenantId, (int) $ownedItem->id));
                });
            });
    }

    private function runIdempotent(string $operation, ?string $key, array $payload, callable $callback, int $responseCode = 200): array
    {
        // Coordinate the optional tenant idempotency record with the database write.
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) {
            $this->idempotencyService->replay($record);
        }
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $responseCode): array {
                $result = $callback($tenantId, $record?->id);
                if ($record !== null) {
                    $this->idempotencyService->markCompleted($record, $responseCode, ['data' => $result]);
                }
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) {
                $this->idempotencyService->markFailed($record);
            }
            throw $exception;
        }
    }

    private function validateQuantity(InventoryItem $item, string $quantity): void
    {
        // Validate resolved acquisition quantity without converting the new DTO string back to a float.
        OwnershipQuantity::normalize($quantity, $item->tracking_mode);
    }

    private function ownedItemResource(int $tenantId, ?OwnedItem $ownedItem): array
    {
        // Build a stable API resource from DTO-safe fields rather than exposing Eloquent models.
        if ($ownedItem === null) {
            throw ValidationException::withMessages(['owned_item_code' => [__('ownership.not_found')]]);
        }
        $balances = $this->repository->movementBalances($tenantId, $ownedItem->id);
        $lots = $ownedItem->acquisitionLots->map(function (AcquisitionLot $lot) use ($tenantId, $ownedItem): array {
            $lotBalance = $this->repository->lotBalances($tenantId, $ownedItem->id, $lot->id);
            return [
                'id' => (int) $lot->id,
                'code' => $lot->code,
                'source_module' => $lot->source_module,
                'source_type' => $lot->source_type,
                'source_code' => $lot->source_code,
                'acquired_at' => $lot->acquired_at?->toDateString(),
                'acquired_quantity' => $lot->acquired_quantity,
                'remaining_quantity' => number_format($lotBalance['owned'], 3, '.', ''),
                'pledged_quantity' => number_format($lotBalance['pledged'], 3, '.', ''),
                'reserved_quantity' => number_format($lotBalance['reserved'] ?? 0, 3, '.', ''),
                'available_quantity' => number_format($lotBalance['owned'] - $lotBalance['pledged'] - ($lotBalance['reserved'] ?? 0), 3, '.', ''),
                'unit_cost_basis' => $lot->unit_cost_basis,
                'estimated_unit_value' => $lot->estimated_unit_value,
                'currency_code' => $lot->currency_code,
                'description' => $lot->description,
            ];
        })->all();
        // Adapt typed Inventory detail while preserving Ownership's existing public response.
        $inventory = $this->inventoryService->itemDetail($ownedItem->inventoryItem->code)->toArray();
        return [
            'id' => (int) $ownedItem->id,
            'code' => $ownedItem->code,
            'inventory_item_code' => $inventory['code'],
            'catalog_item_code' => $inventory['catalog_item_code'],
            'name' => $inventory['name'],
            'description' => $inventory['description'],
            'tracking_mode' => $inventory['tracking_mode'],
            'unit' => $inventory['unit_name'],
            'inventory_total_quantity' => $inventory['total_quantity'],
            'inventory_locations' => array_map(static fn (array $row): array => [
                'location_code' => $row['location']['code'], 'location' => $row['location']['name'],
                'quantity' => $row['quantity'], 'reserved_quantity' => $row['reserved_quantity'],
                'available_quantity' => $row['available_quantity'],
                'is_active' => $row['location']['is_active'], 'is_sellable' => $row['location']['is_sellable'],
            ], $inventory['locations']),
            'owned_quantity' => number_format($balances['owned'], 3, '.', ''),
            'pledged_quantity' => number_format($balances['pledged'], 3, '.', ''),
            'reserved_quantity' => number_format($balances['reserved'] ?? 0, 3, '.', ''),
            'available_quantity' => number_format($balances['owned'] - $balances['pledged'] - ($balances['reserved'] ?? 0), 3, '.', ''),
            'lifecycle_status' => $ownedItem->lifecycle_status,
            'lots' => $lots,
        ];
    }

    public function expiredCollateral(?string $search = null): array
    {
        // Refresh overdue records before returning the tenant's eligible collateral queue.
        $this->expirationService->checkCurrentTenant();
        return $this->collateralItemService->expiredForOwnershipTransfer($search);
    }

    public function transferExpiredCollateral(string $collateralCode, OwnershipAcquisitionCreate $request, ?string $idempotencyKey): array
    {
        // Make the transfer and source status update one retry-safe ownership operation.
        $payload = [...$request->toArray(), 'collateral_code' => $collateralCode];
        return $this->runIdempotent('ownership.pawn_collateral_transfer', $idempotencyKey, $payload,
            function (int $tenantId, ?int $recordId) use ($collateralCode, $request): array {
                return DB::transaction(function () use ($tenantId, $recordId, $collateralCode, $request): array {
                    $item = $this->collateralItemService->findForOwnershipTransferWithLock($collateralCode);
                    $slip = $item->loanContract;
                    if ($slip === null) {
                        throw ValidationException::withMessages(['collateral_code' => ['Collateral must belong to an expired slip.']]);
                    }
                    $this->expirationService->refreshExpiration($slip);
                    $item = $this->collateralItemService->findForOwnershipTransferWithLock($collateralCode);
                    if (strtolower((string) $item->loanContract?->status) !== 'expired'
                        || strtolower((string) $item->item_status) !== 'expired') {
                        throw ValidationException::withMessages(['collateral_code' => ['Only expired collateral can be transferred.']]);
                    }
                    if ($item->ownership_transferred_at !== null) {
                        throw ValidationException::withMessages(['collateral_code' => ['This collateral has already been transferred.']]);
                    }

                    // Materialize an Inventory custody item for legacy collateral created before the link existed.
                    if ($item->inventory_item_id === null) {
                        $inventoryItem = $this->inventoryService->receivePawnCollateral($item);
                        $item = $this->collateralItemService->linkInventoryItem($item, (int) $inventoryItem->id);
                        $item->load('inventoryItem');
                    }

                    // Record tenant ownership against the original collateral source code.
                    $acquisition = new OwnershipAcquisitionCreate(
                        inventoryItemCode: (string) $item->inventoryItem?->code,
                        quantity: max(1, (int) $item->quantity),
                        acquiredAt: $request->acquiredAt,
                        unitCostBasis: $request->unitCostBasis,
                        estimatedUnitValue: $request->estimatedUnitValue,
                        currencyCode: $request->currencyCode,
                        description: $request->description,
                    );
                    $ownedItem = $this->recordAcquisition($tenantId, $acquisition, 'PAWN', 'PawnCollateralItem', $item->code, $recordId);
                    $this->collateralItemService->markOwnershipTransferred($item);
                    $this->recordAudit('pawn.collateral.ownership_transferred', get_class($item), $item->id, ['collateral_code' => $item->code, 'owned_item_code' => $ownedItem['code'] ?? null, 'acquisition_code' => $ownedItem['acquisition_code'] ?? null]);
                    return ['collateral_code' => $item->code, 'owned_item' => $ownedItem];
                });
            }, 201);
    }
}
