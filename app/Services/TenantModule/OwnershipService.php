<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\OwnershipAcquisitionCreate;
use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\AcquisitionLot;
use App\Models\OwnershipModule\OwnedItem;
use App\Repository\OwnershipRepository;
use App\Services\PawnModule\CollateralItemService;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
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
    ) {
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

    public function reserveSale(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode): array
    {
        // Keep confirmed Sale quantity in the ownership ledger without reducing ownership.
        return $this->changeQuantity('ownership.sale.reserve', $ownedItemCode, $lotCode, $quantity, 'SaleOrderLine', $sourceCode,
            null, 'SALE_RESERVE', 0, 0, 1);
    }

    public function releaseSaleReservation(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode): array
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

    public function inventoryOptions(?string $search = null): array
    {
        // Reuse Inventory's tenant-scoped item listing for Ownership acquisition selectors.
        return $this->inventoryService->items($search);
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

    public function reservePledge(string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, ?string $idempotencyKey = null): array
    {
        // Reserve quantity against a specific lot while leaving total ownership unchanged.
        return $this->changeQuantity('ownership.pledge', $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode,
            $idempotencyKey, 'PLEDGE', 0, 1);
    }

    public function releasePledge(string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, ?string $idempotencyKey = null): array
    {
        // Release a prior pledge against the same lot without creating another acquisition.
        return $this->changeQuantity('ownership.release', $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode,
            $idempotencyKey, 'RELEASE', 0, -1);
    }

    public function reduceForSale(string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, ?string $idempotencyKey = null): array
    {
        // Reduce only unpledged ownership when a future Sales workflow records a sale.
        return $this->changeQuantity('ownership.sale', $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode,
            $idempotencyKey, 'SALE', -1, 0);
    }

    public function restoreForSaleReturn(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode): array
    {
        // Restore ownership against the original FIFO lot when a delivered item is returned.
        return $this->changeQuantity('ownership.sale.return', $ownedItemCode, $lotCode, $quantity, 'SaleReturnLine', $sourceCode,
            null, 'SALE_RETURN', 1, 0);
    }

    // Reduce the originating acquisition lot when goods are returned to their supplier.
    public function reduceForPurchaseReturn(string $ownedItemCode, string $lotCode, float $quantity, string $sourceCode, ?string $idempotencyKey = null): array
    {
        return $this->changeQuantity('ownership.purchase_return', $ownedItemCode, $lotCode, $quantity,
            'PurchaseReturnLine', $sourceCode, $idempotencyKey, 'PURCHASE_RETURN', -1, 0);
    }

    public function reduceForfeiture(string $ownedItemCode, string $lotCode, float $quantity, string $sourceType, string $sourceCode, bool $fromPledge = true, ?string $idempotencyKey = null): array
    {
        // Remove forfeited ownership and, when forfeiting pledged goods, clear that reservation too.
        return $this->changeQuantity('ownership.forfeiture', $ownedItemCode, $lotCode, $quantity, $sourceType, $sourceCode,
            $idempotencyKey, 'FORFEITURE', -1, $fromPledge ? -1 : 0);
    }

    private function recordAcquisition(int $tenantId, OwnershipAcquisitionCreate $request, string $sourceModule, string $sourceType, ?string $sourceCode, ?int $idempotencyRecordId): array
    {
        // Lock the tenant-owned Inventory item before creating its Ownership aggregate and lot.
        return DB::transaction(function () use ($tenantId, $request, $sourceModule, $sourceType, $sourceCode, $idempotencyRecordId): array {
            $inventoryItem = $this->inventoryService->findForOwnershipByCode($request->inventoryItemCode);
            if ($inventoryItem->tenant_id !== $tenantId) {
                throw ValidationException::withMessages(['inventory_item_code' => [__('ownership.inventory_unavailable')]]);
            }
            $this->validateQuantity($inventoryItem, $request->quantity);
            if ($sourceCode !== null && $this->repository->findBySourceCode($tenantId, $sourceModule, $sourceType, $sourceCode) !== null) {
                throw ValidationException::withMessages(['source_code' => [__('ownership.source_already_recorded')]]);
            }

            $ownedItem = $this->repository->ownedItemForInventory($tenantId, $inventoryItem->id, true);
            if ($inventoryItem->tracking_mode === 'UNIQUE' && $ownedItem !== null
                && $this->repository->movementBalances($tenantId, $ownedItem->id)['owned'] > 0) {
                throw ValidationException::withMessages(['inventory_item_code' => [__('ownership.unique_already_owned')]]);
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
            $lot = $this->repository->createLot($tenantId, [
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
            $this->repository->createMovement($tenantId, [
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
            return $this->ownedItemResource($tenantId, $this->repository->ownedItem($tenantId, $ownedItem->id));
        });
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
                    // Resolve business codes into internal ledger keys while holding tenant-scoped locks.
                    $ownedItem = $this->repository->ownedItemByCode($tenantId, $ownedItemCode, true);
                    if ($ownedItem === null) {
                        throw ValidationException::withMessages(['owned_item_code' => [__('ownership.not_found')]]);
                    }
                    $lot = $this->repository->lotByCode($tenantId, (int) $ownedItem->id, $lotCode, true);
                    if ($lot === null) {
                        throw ValidationException::withMessages(['acquisition_lot_code' => [__('ownership.lot_not_found')]]);
                    }
                    if (! is_finite($quantity) || $quantity <= 0) {
                        throw ValidationException::withMessages(['quantity' => [__('ownership.quantity_positive')]]);
                    }
                    $balances = $this->repository->lotBalances($tenantId, (int) $ownedItem->id, (int) $lot->id);
                    $available = $balances['owned'] - $balances['pledged'] - ($balances['reserved'] ?? 0);
                    if (($ownedSign < 0 && $quantity > $available) || ($pledgedSign < 0 && $quantity > $balances['pledged'])
                        || ($pledgedSign > 0 && $quantity > $available)
                        || ($reservedSign < 0 && $quantity > ($balances['reserved'] ?? 0))) {
                        throw ValidationException::withMessages(['quantity' => [__('ownership.insufficient_lot_balance')]]);
                    }
                    $this->repository->createMovement($tenantId, [
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

    private function validateQuantity(InventoryItem $item, float $quantity): void
    {
        // Keep the ownership quantity precision compatible with physical tracking mode.
        if (! is_finite($quantity) || $quantity <= 0 || round($quantity, 3) !== $quantity) {
            throw ValidationException::withMessages(['quantity' => [__('ownership.quantity_precision')]]);
        }
        if ($item->tracking_mode === 'UNIQUE' && $quantity !== 1.0) {
            throw ValidationException::withMessages(['quantity' => [__('ownership.unique_quantity')]]);
        }
        if ($item->tracking_mode === 'SERIALIZED' && floor($quantity) !== $quantity) {
            throw ValidationException::withMessages(['quantity' => [__('ownership.serialized_quantity')]]);
        }
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
        $inventory = $this->inventoryService->detailByCode($ownedItem->inventoryItem->code);
        return [
            'id' => (int) $ownedItem->id,
            'code' => $ownedItem->code,
            'inventory_item_code' => $inventory['code'],
            'catalog_item_code' => $inventory['catalog_item_code'],
            'name' => $inventory['name'],
            'description' => $inventory['description'],
            'tracking_mode' => $inventory['tracking_mode'],
            'unit' => $inventory['unit'],
            'inventory_total_quantity' => $inventory['total_quantity'],
            'inventory_locations' => $inventory['locations'],
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
                    return ['collateral_code' => $item->code, 'owned_item' => $ownedItem];
                });
            }, 201);
    }
}
