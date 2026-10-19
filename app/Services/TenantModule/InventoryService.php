<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\InventoryLocationDetail;
use App\DataObjects\ResponseObjects\InventoryLockedStock;
use App\Models\InventoryModule\InventoryReservation;
use App\Enums\InventoryIssueContext;
use App\Models\InventoryModule\InventoryMovement;
use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\InventoryModule\InventorySerializedItem;
use App\Models\PawnModule\PawnCollateralItem;
use App\DataObjects\RequestObjects\InventoryStockCommand;
use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\DataObjects\RequestObjects\InventoryLocationCreate;
use App\DataObjects\RequestObjects\InventoryLocationUpdate;
use App\DataObjects\RequestObjects\InventoryMoveRequest;
use App\DataObjects\RequestObjects\InventoryIssueRequest;
use App\DataObjects\RequestObjects\InventoryReservationRequest;
use App\DataObjects\RequestObjects\InventoryAdjustmentRequest;
use App\DataObjects\RequestObjects\InventorySaleReturnRequest;
use App\DataObjects\RequestObjects\InventoryMovementContext;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\TenantNotFound;
use App\Support\InventoryQuantity;
use App\Repository\InventoryRepository;
use App\Utility\FileStorageUtility;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\TenantIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\DataObjects\RequestObjects\InventoryReservationReleaseRequest;
use App\DataObjects\RequestObjects\InventoryOperationContext;
use App\DataObjects\ResponseObjects\InventoryMutationResult;
use App\Enums\InventoryOperationOwner;
use App\Exceptions\IdempotentReplayResponse;
use App\Support\OperationLogger;
use Illuminate\Database\QueryException;
use App\DataObjects\RequestObjects\InventoryListFilter;
use App\DataObjects\RequestObjects\InventoryPageFilter;
use App\DataObjects\RequestObjects\InventorySerializedItemFilter;
use App\DataObjects\ResponseObjects\InventoryItemSummary;
use App\DataObjects\ResponseObjects\InventoryItemDetail;
use App\DataObjects\ResponseObjects\InventoryItemListPage;
use App\DataObjects\ResponseObjects\InventoryLocationBalance;
use App\DataObjects\ResponseObjects\InventorySerializedItemDetail;
use App\DataObjects\ResponseObjects\InventorySerializedItemListPage;
use App\DataObjects\ResponseObjects\InventoryMovementDetail;
use App\DataObjects\ResponseObjects\InventoryMovementListPage;
use LogicException;
use Throwable;

class InventoryService extends BaseTenantService
{
    public function __construct(
        private InventoryRepository $repository,
        private CatalogItemService $catalogItemService,
        private CatalogTaxonomyService $catalogTaxonomyService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $tableIdGenerationService,
        private FileStorageUtility $fileStorageUtility,
    ) {
    }

    public function locations(): array
    {
        return $this->repository->locations($this->resolveCurrentTenantId())->map(
            fn (InventoryLocation $location): array => $this->locationResource($location)
        )->all();
    }

    public function measurementUnits(): array
    {
        // Return catalog unit business codes for Inventory requests, omitting database IDs.
        return array_map(static fn (array $unit): array => [
            'code' => $unit['code'],
            'name' => $unit['name'],
            'symbol' => $unit['symbol'],
        ], $this->catalogTaxonomyService->units());
    }

    public function createLocation(InventoryLocationCreate $request): array
    {
        // Validate internal DTO callers as well as the HTTP boundary.
        $this->validateLocationType($request->type);

        // Public location creation owns one primary audit and its atomic idempotency result.
        $context = new InventoryOperationContext(
            operation: 'inventory.location.create',
            owner: InventoryOperationOwner::PublicInventory,
            responseCode: 201,
            auditAction: 'inventory.location.created',
            auditTargetType: InventoryLocation::class,
        );

        return $this->runStockOperation($context, $request, function (int $tenantId) use ($request): array {
            // Serialize name creation and reject duplicates before allocating or inserting.
            $this->lockLocationCreationNamespace();
            $this->assertLocationNameAvailable($request->name);

            // Return the existing typed location contract without changing the API fields.
            $location = $this->persistNewInventoryLocation($tenantId, $request);

            return InventoryLocationDetail::fromModel($location)->toArray();
        });
    }

    public function updateLocationByCode(InventoryLocationUpdate $request): array
    {
        // Reuse supported-type validation for internal partial-edit callers.
        if ($request->type !== null) {
            $this->validateLocationType($request->type);
        }
        $context = new InventoryOperationContext(
            operation: 'inventory.location.update',
            owner: InventoryOperationOwner::PublicInventory,
            auditAction: 'inventory.location.updated',
            auditTargetType: InventoryLocation::class,
        );

        return $this->runStockOperation($context, $request, function () use ($request): array {
            // Serialize renames with creation before acquiring the target edit lock.
            if ($request->name !== null) {
                $this->lockLocationCreationNamespace();
            }
            $location = $this->lockLocationForEdit($request);

            // Validate duplicate names and active operation restrictions under the edit lock.
            $this->validateLocationEdit($location, $request);
            $this->persistLocationEdit($location, $request);

            return InventoryLocationDetail::fromModel($location)->toArray();
        });
    }


    /** Build bounded item summaries using the completed page and batched balance queries. */
    public function itemSummaryPage(InventoryListFilter $filter): InventoryItemListPage
    {
        // Fetch references once per page and prepare shared stock/reservation/location lookups.
        $page = $this->repository->itemPage($filter);
        $items = collect($page->items());
        $balances = $this->readItemBalanceData($items);
        $summaries = $items->map(fn (InventoryItem $item): InventoryItemSummary =>
            $this->buildItemReadSummary($item, $this->buildItemReadLocations($item, $balances)),
        )->all();

        return InventoryItemListPage::fromPaginator($page, $summaries);
    }

    /** Return bounded item metadata and balances without loading every serialized identity. */
    public function itemDetail(string $itemCode): InventoryItemDetail
    {
        // Resolve the item with eager references and reuse the same page balance projection.
        $item = $this->resolveItemForRead($itemCode);
        $locations = $this->buildItemReadLocations($item, $this->readItemBalanceData(collect([$item])));

        return new InventoryItemDetail($this->buildItemReadSummary($item, $locations), $locations);
    }

    /** Page immutable history with the repository's eager-loaded provenance relations. */
    public function movementHistoryPage(string $itemCode, InventoryPageFilter $filter): InventoryMovementListPage
    {
        // Resolve once; DTO construction performs no relationship queries.
        $item = $this->resolveItemForRead($itemCode);
        $page = $this->repository->movementPage((int) $item->id, $filter);
        $rows = collect($page->items())->map(fn (InventoryMovement $movement): InventoryMovementDetail =>
            $this->buildMovementReadDetail($item, $movement),
        )->all();

        return InventoryMovementListPage::fromPaginator($page, $rows);
    }

    /** Page physical identities separately from measurement-unit options and item summaries. */
    public function serializedItemsPage(string $itemCode, InventorySerializedItemFilter $filter): InventorySerializedItemListPage
    {
        // Existing joined custody/reservation projections supply all row values in one page query.
        $item = $this->resolveItemForRead($itemCode);
        $page = $this->repository->serializedItemPage((int) $item->id, $filter);
        $rows = collect($page->items())->map(fn (InventorySerializedItem $serializedItem): InventorySerializedItemDetail =>
            $this->buildSerializedItemReadDetail($item, $serializedItem),
        )->all();

        return InventorySerializedItemListPage::fromPaginator($page, $rows);
    }

    public function items(?string $search): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        return $this->repository->items($tenantId, $search)->map(
            fn (InventoryItem $item): array => $this->itemResource($tenantId, $item)
        )->all();
    }

    public function detailByCode(string $itemCode): array
    {
        // Resolve a public business code to an internal model before constructing the response.
        $tenantId = $this->resolveCurrentTenantId();
        // The repository applies TenantContext through the model scope.
        $item = $this->repository->findItemByCode($itemCode);
        if ($item === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }
        return $this->itemResource($tenantId, $item);
    }

    public function findForOwnershipByCode(string $itemCode): InventoryItem
    {
        // Resolve a public business code before Ownership uses the internal model ID.
        $item = $this->repository->findItemByCode($itemCode, true);
        if ($item === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }
        return $item;
    }

    public function movementsByCode(string $itemCode): array
    {
        // Resolve the public Inventory item code before reading movement history.
        $tenantId = $this->resolveCurrentTenantId();
        // The repository applies TenantContext through the model scope.
        $item = $this->repository->findItemByCode($itemCode);
        if ($item === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }
        return $this->repository->movements($tenantId, (int) $item->id)->map(
            fn ($movement): array => [
                'code' => $movement->code,
                'type' => $movement->movement_type,
                'quantity' => $movement->quantity,
                'from_location_code' => $movement->fromLocation?->code,
                'to_location_code' => $movement->toLocation?->code,
                'from' => $movement->fromLocation?->name,
                'to' => $movement->toLocation?->name,
                'reason' => $movement->reason,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ]
        )->all();
    }

    public function receive(
        InventoryReceiveRequest $request,
        InventoryOperationOwner $owner = InventoryOperationOwner::BusinessCaller,
    ): array {
        // Audit ownership is explicit; source metadata never grants a bypass.
        $operation = new InventoryOperationContext(
            operation: 'inventory.receive',
            owner: $owner,
            responseCode: 201,
            auditAction: 'inventory.received',
            auditTargetType: InventoryItem::class,
        );

        return $this->runStockOperation($operation, $request, function (int $tenantId, ?int $recordId) use ($request): array {
            // Resolve/create the item anchor before acquiring its destination location lock.
            $item = $request->inventoryItemCode === null
                ? $this->createInventoryItem($tenantId, $request)
                : $this->lockedItemByCode($tenantId, $request->inventoryItemCode);
            $locations = $this->lockSelectedStockLocations([$request->locationCode]);
            $location = $locations->get($request->locationCode);

            // Reuse exact tracking, reference, identifier, and current UNIQUE custody checks.
            $quantity = $this->validateReceiptForItem($item, $location, $request);
            $identifiers = $this->validateSerializedReceive($tenantId, $item, $request->quantity, $request->serializedItemIdentifiers);
            $context = new InventoryMovementContext(
                reason: $request->reason,
                sourceType: $request->sourceType,
                sourceCode: $request->sourceCode,
                idempotencyRecordId: $recordId,
            );

            return $this->appendReceiptMovements($tenantId, $item, $location, $quantity, $identifiers, $context)->toArray();
        });
    }

    // Receive issued serialized items into sellable or quarantine custody without duplicating their identities.
    // Resolve a tenant location internally so return lines can retain the required location foreign key.
    public function locationForSaleReturn(string $locationCode): InventoryLocation
    {
        // TenantContext determines the location's tenant through the model scope.
        $location = $this->repository->findLocationByCode($locationCode);
        // Distinguish a missing location from an existing inactive location.
        if ($location === null) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }
        if (! $location->is_active) {
            throw new InvalidTenantRequest(__('inventory.location_unavailable'));
        }
        return $location;
    }


    public function receiveSaleReturn(InventorySaleReturnRequest $request): array
    {
        // SaleReturnService owns financial posting and its primary audit.
        $operation = new InventoryOperationContext(operation: 'inventory.receive.sale_return', owner: InventoryOperationOwner::BusinessCaller, responseCode: 201);
        return $this->runStockOperation($operation, $request, function (int $tenantId, ?int $recordId) use ($request): array {
            $stock = $this->lockStockForOperation([$request->inventoryItemCode], [$request->locationCode], serializedItemCodes: $request->serializedItemCodes);
            $item = $stock->items->get($request->inventoryItemCode);
            $location = $stock->locations->get($request->locationCode);
            $quantity = $this->validateStockCommandQuantity($request, $item);
            if (! $location->is_active || $request->sourceCode === '') {
                throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
            }
            // Recheck return disposition after acquiring the location lock.
            if ($request->expectedSellable !== null && (bool) $location->is_sellable !== $request->expectedSellable) {
                throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
            }
            // Existing identities may return only from a recorded issued state.
            foreach ($stock->serializedItems as $unit) {
                $last = $stock->custody->get($unit->id);
                if ((int) $unit->inventory_item_id !== (int) $item->id || $last === null || $last->to_location_id !== null) {
                    throw new InvalidTenantRequest(__('inventory.serialized_unavailable'));
                }
            }
            if ($item->tracking_mode === 'UNIQUE') $this->assertUniqueItemHasNoCustody($item);
            $context = new InventoryMovementContext(reason: 'Customer sale return', sourceType: 'SaleReturnLine', sourceCode: $request->sourceCode, idempotencyRecordId: $recordId);
            $movements = collect();
            foreach ($stock->serializedItems as $unit) {
                $movements->push($this->appendMovement($tenantId, $item, null, $location->id, '1.000', 'RECEIVE', $context, $unit->id));
            }
            if ($item->tracking_mode !== 'SERIALIZED') {
                $movements->push($this->appendMovement($tenantId, $item, null, $location->id, InventoryQuantity::format($quantity), 'RECEIVE', $context));
            }
            return $this->buildStockMutationResult($item, $quantity, $movements, $request->serializedItemCodes)->toArray();
        });
    }

    /** Prelock every existing item before a business caller touches locations or reservations. */
    public function prelockItems(array $codes): Collection
    {
        // Reuse ordered tenant-scoped item locks and reject incomplete reference sets.
        $this->assertStockValidationTransaction();
        $codes = $this->normalizeStockReferenceCodes($codes);
        return $codes === [] ? collect() : $this->lockSelectedStockItems($codes);
    }

    /** Resolve a movement for internal persistence without exposing its database ID publicly. */
    public function movementForSource(string $sourceType, string $sourceCode): InventoryMovement
    {
        // Missing provenance must roll back the caller instead of persisting an empty linkage.
        return $this->repository->movementBySource($this->resolveCurrentTenantId(), $sourceType, $sourceCode)
            ?? throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
    }

    /** Return current locked, sale-eligible locations and exact available quantities. */
    public function saleLocationStock(string $itemCode): array
    {
        $this->prelockItems([$itemCode]);
        $locations = $this->repository->locations($this->resolveCurrentTenantId())->filter(fn ($location) => $location->is_active && $location->is_sellable);
        if ($locations->isEmpty()) return [];
        $stock = $this->lockStockForOperation([$itemCode], $locations->pluck('code')->all());
        $item = $stock->items->get($itemCode);
        // Internal location models carry IDs needed by Sales without changing public DTOs.
        return $stock->locations->map(function ($location) use ($stock, $item): \App\DataObjects\ResponseObjects\InventorySaleLocationStock {
            [$reserved] = $this->summarizeLockedReservations($this->activeLockedReservationsAtLocation($stock, $item, $location), $item);
            return new \App\DataObjects\ResponseObjects\InventorySaleLocationStock($location, max(0, ($stock->balances[$item->id][$location->id] ?? 0) - $reserved));
        })->all();
    }

    /** Select only the required identities in bounded pages; reservation revalidates under locks. */
    public function saleSerializedItemCodes(string $itemCode, string $locationCode, int $count, array $excluded = []): array
    {
        // A fractional serialized allocation must never accidentally select every row.
        if ($count <= 0) return [];
        $item = $this->prelockItems([$itemCode])->get($itemCode);
        $selected = [];
        $page = 1;
        do {
            $result = $this->repository->serializedItemPage($item->id, new InventorySerializedItemFilter(page: $page, perPage: min(100, $count), locationCode: $locationCode, availableOnly: true), true);
            foreach ($result->items() as $unit) {
                if (! in_array($unit->code, $excluded, true)) $selected[] = $unit->code;
                if (count($selected) === $count) return $selected;
            }
        } while (++$page <= $result->lastPage());
        return $selected;
    }


    public function reserveForSale(InventoryReservationRequest $request): array
    {
        // Sales owns its financial postings and primary audit.
        $context = new InventoryOperationContext(
            operation: 'inventory.reserve.sale',
            owner: InventoryOperationOwner::BusinessCaller,
            responseCode: 201,
        );

        return $this->runStockOperation($context, $request, function (int $tenantId) use ($request): array {
            // Reuse complete-set ordered locks and exact sellable availability validation.
            $stock = $this->lockStockForOperation(
                [$request->inventoryItemCode],
                [$request->locationCode],
                serializedItemCodes: $request->serializedItemCodes,
            );
            $quantity = $this->validateLockedStockAvailability($stock, $request);
            $item = $stock->items->get($request->inventoryItemCode);
            $location = $stock->locations->get($request->locationCode);

            // Persist the reservation and advance its item version within the same transaction.
            $reservation = $this->persistSaleStockReservation($tenantId, $item, $location, $request);
            $this->advanceItemVersion($item);

            return $this->buildStockMutationResult($item, $quantity, collect(), $request->serializedItemCodes, $reservation)->toArray();
        });
    }

    public function releaseSaleReservation(InventoryReservationReleaseRequest $request): array
    {
        // Business callers retain their primary audit; release idempotency is atomic with their work.
        $context = new InventoryOperationContext(
            operation: 'inventory.reservation.release',
            owner: InventoryOperationOwner::BusinessCaller,
        );

        return $this->runStockOperation($context, $request, function () use ($request): array {
            // Keep item/location/reservation locks ordered and reuse full/partial release invariants.
            $stock = $this->lockStockForOperation(
                [$request->inventoryItemCode],
                [$request->locationCode],
                [$request->reservationCode],
                $request->serializedItemCodes,
            );

            return $this->releaseLockedStockReservation($stock, $request)->toArray();
        });
    }

    public function moveStock(InventoryMoveRequest $request): array
    {
        // A direct Inventory move owns its success audit and replay response.
        $operation = new InventoryOperationContext(
            operation: 'inventory.move',
            owner: InventoryOperationOwner::PublicInventory,
            auditAction: 'inventory.moved',
            auditTargetType: InventoryItem::class,
        );

        return $this->runStockOperation($operation, $request, function (int $tenantId, ?int $recordId) use ($request): array {
            // Reuse ordered locks, optimistic version checks, exact availability, and current custody.
            $stock = $this->lockStockForOperation(
                [$request->inventoryItemCode],
                [$request->fromLocationCode, $request->toLocationCode],
                serializedItemCodes: $request->serializedItemCodes,
            );
            $quantity = $this->validateLockedStockAvailability($stock, $request);
            $context = new InventoryMovementContext(reason: $request->reason, idempotencyRecordId: $recordId);

            return $this->appendMoveMovements($tenantId, $stock, $request, $quantity, $context)->toArray();
        });
    }

    public function issueStock(InventoryIssueRequest $request): array
    {
        // Business workflows retain their accounting and primary audit completion.
        $operation = new InventoryOperationContext(
            operation: 'inventory.issue',
            owner: $request->operationContext === InventoryIssueContext::Manual
                ? InventoryOperationOwner::PublicInventory : InventoryOperationOwner::BusinessCaller,
            auditAction: 'inventory.issued',
            auditTargetType: InventoryItem::class,
        );

        return $this->runStockOperation($operation, $request, function (int $tenantId, ?int $recordId) use ($request): array {
            // Bind trusted provenance before acquiring the complete stock lock set.
            $this->validateIssueSourceContext($request);
            $stock = $this->lockStockForOperation(
                [$request->inventoryItemCode],
                [$request->locationCode],
                $request->reservationCode === null ? [] : [$request->reservationCode],
                $request->serializedItemCodes,
            );
            $quantity = $this->validateLockedStockAvailability($stock, $request);
            $reservation = $request->reservationCode === null
                ? null : $this->consumeLockedStockReservation($stock, $request);

            // Append exact physical effects after reservation consumption in the same transaction.
            $context = new InventoryMovementContext(reason: $request->reason, sourceType: $request->sourceType, sourceCode: $request->sourceCode, idempotencyRecordId: $recordId);
            return $this->appendIssueMovements($tenantId, $stock, $request, $quantity, $context, $reservation)->toArray();
        });
    }

    public function adjustStock(InventoryAdjustmentRequest $request): array
    {
        // Standalone adjustments own their audit and atomic idempotency completion.
        $operation = new InventoryOperationContext(
            operation: 'inventory.adjust',
            owner: InventoryOperationOwner::PublicInventory,
            auditAction: 'inventory.adjusted',
            auditTargetType: InventoryItem::class,
        );

        return $this->runStockOperation($operation, $request, function (int $tenantId, ?int $recordId) use ($request): array {
            // Reuse ordered locks and keep movement provenance internal.
            $stock = $this->lockStockForOperation(
                [$request->inventoryItemCode],
                [$request->locationCode],
                serializedItemCodes: $request->serializedItemCodes,
            );
            $item = $stock->items->get($request->inventoryItemCode);
            $location = $stock->locations->get($request->locationCode);
            $context = new InventoryMovementContext(reason: $request->reason, sourceType: $request->sourceType, sourceCode: $request->sourceCode, idempotencyRecordId: $recordId);

            // Outbound adjustment requires exact unreserved availability and current custody.
            if ($request->direction === 'OUT') {
                $quantity = $this->validateLockedStockAvailability($stock, $request);
                return $this->appendIssueMovements($tenantId, $stock, $request, $quantity, $context, null, 'ADJUST_OUT')->toArray();
            }

            // Inbound adjustment reuses tracking/identifier checks and current UNIQUE custody.
            if ($request->direction !== 'IN' || ! $location->is_active || $request->serializedItemCodes !== []) {
                throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
            }
            $quantity = $this->validateStockCommandQuantity($request, $item);
            $identifiers = $this->validateSerializedReceive($tenantId, $item, $request->quantity, $request->serializedItemIdentifiers);
            if ($item->tracking_mode === 'UNIQUE') {
                $this->assertUniqueItemHasNoCustody($item);
            }

            return $this->appendReceiptMovements($tenantId, $item, $location, $quantity, $identifiers, $context, 'ADJUST_IN')->toArray();
        });
    }


    public function ensureMainShopForTenant(int $tenantId): void
    {
        $this->repository->ensureMainShop($tenantId,
            $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_locations', CarbonImmutable::now()));
    }

    public function receivePawnCollateral(PawnCollateralItem $collateral): InventoryItem
    {
        // Retain existing tenant bootstrap and the collateral caller's model-return contract.
        $tenantId = $this->resolveCurrentTenantId();
        if ((int) $collateral->tenant_id !== $tenantId) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }
        $this->ensureMainShopForTenant($tenantId);
        $location = $this->repository->defaultLocation($tenantId);
        if ($location === null) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }

        // Record custody through receipt orchestration without acquiring ownership or redeeming.
        $result = $this->receive(new InventoryReceiveRequest(
            locationCode: $location->code,
            quantity: max(1, (int) $collateral->quantity),
            name: $collateral->name,
            description: mb_substr(trim(implode(' | ', array_filter([
                'Pawn collateral '.$collateral->code,
                $collateral->brand_name,
                $collateral->description
                ]))), 0, 255),
            trackingMode: 'QUANTITY',
            unitCode: 'unit',
            reason: 'Pawn collateral received into custody',
            sourceType: 'PawnCollateralItem',
            sourceCode: $collateral->code,
            idempotencyKey: 'pawn-custody-'.$collateral->code,
        ));

        return $this->findForOwnershipByCode($result['inventory_item_code']);
    }

    /**
     * Validate a typed command against its resolved tenant-owned item.
     * Returns exact integer thousandths for future mutation orchestration.
     */
    public function validateStockCommandQuantity(
        InventoryReceiveRequest|InventoryMoveRequest|InventoryIssueRequest|InventoryReservationRequest|InventoryAdjustmentRequest|InventorySaleReturnRequest $request,
        InventoryItem $item,
    ): int
    {
        // Reject a model from another tenant before trusting tracking or identity attributes.
        if ((int) $item->tenant_id !== $this->resolveCurrentTenantId()) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }

        // Enforce tracking precision and unit-selection shape using exact quantities.
        $quantity = InventoryQuantity::parse($request->quantity, $item->tracking_mode);
        if ($request->inventoryItemCode !== null && $request->inventoryItemCode !== $item->code) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }
        if (! $request instanceof InventoryReceiveRequest
            && ! ($request instanceof InventoryAdjustmentRequest && $request->direction === 'IN')) {
            $this->validateSerializedItemSelection($item, $quantity, $request->serializedItemCodes);
        }
        // Validate optional receipt references against the already resolved item as well.
        if ($request instanceof InventoryReceiveRequest) {
            $this->validateExistingReceiptReferences($request, $item);
            $this->validateSerializedReceive($this->resolveCurrentTenantId(), $item, $request->quantity, $request->serializedItemIdentifiers);
        }
        if ($request instanceof InventoryAdjustmentRequest && $request->direction === 'IN') {
            $this->validateSerializedReceive($this->resolveCurrentTenantId(), $item, $request->quantity, $request->serializedItemIdentifiers);
        }

        return $quantity;
    }


    /**
     * Prepare all stock dependencies inside an already active caller transaction.
     * Multi-item callers must pass their complete sets once before processing lines.
     * Rebuild this snapshot after a mutation; never reuse it across transactions.
     *
     * @param list<string> $itemCodes
     * @param list<string> $locationCodes
     * @param list<string> $reservationCodes
     * @param list<string> $serializedItemCodes
     */
    public function lockStockForOperation(
        array $itemCodes,
        array $locationCodes,
        array $reservationCodes = [],
        array $serializedItemCodes = [],
    ): InventoryLockedStock {
        // Normalize and validate the complete selection before locking.
        $itemCodes = $this->normalizeStockReferenceCodes($itemCodes);
        $locationCodes = $this->normalizeStockReferenceCodes($locationCodes);
        $reservationCodes = $this->normalizeStockReferenceCodes($reservationCodes);
        $serializedItemCodes = $this->normalizeStockReferenceCodes($serializedItemCodes);
        $this->assertStockSelectionNotEmpty($itemCodes, $locationCodes);

        // Acquire anchors first, followed by reservations and serialized identities.
        $items = $this->lockSelectedStockItems($itemCodes);
        $locations = $this->lockSelectedStockLocations($locationCodes);
        $itemIds = $items->pluck('id')->all();
        $reservations = $this->lockSelectedStockReservations($itemIds, $reservationCodes);
        $serializedItems = $this->lockSelectedSerializedItems($itemIds, $serializedItemCodes);

        // Construct current balances and custody after all selection locks are held.
        return $this->buildLockedStockSnapshot($items, $locations, $reservations, $serializedItems);
    }

    /**
     * Validate an outgoing command against a current, transaction-protected snapshot.
     * Returns exact integer thousandths; this helper performs no stock writes.
     * Packing/loan-collateral restrictions require their future authoritative integrations.
     */
    public function validateLockedStockAvailability(
        InventoryLockedStock $stock,
        InventoryIssueRequest|InventoryReservationRequest|InventoryMoveRequest|InventoryAdjustmentRequest $request,
    ): int {
        // Resolve the transaction-protected item and source location.
        $this->assertStockValidationTransaction();
        $item = $this->resolveLockedStockItem($stock, $request->inventoryItemCode);
        $locationCode = $request instanceof InventoryMoveRequest ? $request->fromLocationCode : $request->locationCode;
        $location = $this->resolveLockedStockLocation($stock, $locationCode);

        // Validate operation-specific location, version, direction, and source requirements.
        $this->validateLockedStockOperation($stock, $request, $item, $location);
        $this->validateLockedStockLocationEligibility($request, $location);
        $this->validateStockReservationSource($request);

        // Validate quantity and any supplied originating reservation.
        $quantity = $this->validateStockCommandQuantity($request, $item);
        $ownReservation = $request instanceof InventoryIssueRequest
            ? $this->validateLockedIssueReservation($stock, $request, $item, $location, $quantity)
            : null;

        // Calculate live reserved stock and compare exact physical availability.
        $reservations = $this->activeLockedReservationsAtLocation($stock, $item, $location);
        [$reserved, $reservedCodes] = $this->summarizeLockedReservations($reservations, $item);
        $physical = $stock->balances[$item->id][$location->id] ?? 0;
        $this->assertLockedStockQuantityAvailable($physical, $reserved, $quantity, $ownReservation);

        // Validate selected serialized membership and current custody.
        $this->validateLockedSerializedItemAvailability($stock, $request, $item, $location, $reservedCodes, $ownReservation);

        return $quantity;
    }


    /**
     * Consume a validated locked reservation before appending the issue movements.
     * The caller must append those movements in this same transaction; their existing
     * version increments cover the issue, so consumption does not add another increment.
     */
    public function consumeLockedStockReservation(
        InventoryLockedStock $stock,
        InventoryIssueRequest $request,
    ): InventoryReservation {
        // Validate exact stock, custody, original source, and remaining reservation membership.
        $quantity = $this->validateLockedStockAvailability($stock, $request);
        $item = $this->resolveLockedStockItem($stock, $request->inventoryItemCode);
        $location = $this->resolveLockedStockLocation($stock, $request->locationCode);
        $reservation = $this->validateLockedIssueReservation($stock, $request, $item, $location, $quantity);
        if ($reservation === null) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }

        // Subtract exactly the consumed quantity and serialized codes from the locked record.
        $remainingCodes = $this->remainingReservationCodes($item, $reservation, $request->serializedItemCodes);
        $remaining = $this->reservationRemainingAfterMutation($item, $reservation, $quantity, $remainingCodes);
        $this->persistLockedReservationBalance($reservation, $remaining, $remainingCodes, 'CONSUMED');

        // Let issue orchestration build its final versioned result after physical appends.
        return $reservation;
    }

    /**
     * Release full or explicitly selected reserved stock inside the owning transaction.
     * Uses the prepared lock set and preserves full-release idempotence at exact zero.
     */
    public function releaseLockedStockReservation(
        InventoryLockedStock $stock,
        InventoryReservationReleaseRequest $request,
    ): InventoryMutationResult {
        // Resolve and bind the locked reservation before considering any no-op release.
        $this->assertStockValidationTransaction();
        $item = $this->resolveLockedStockItem($stock, $request->inventoryItemCode);
        $location = $this->resolveLockedStockLocation($stock, $request->locationCode);
        $reservation = $this->validateLockedReleaseReservation($stock, $request, $item, $location);
        $quantity = $this->resolveReservationReleaseQuantity($request, $item, $reservation);

        // Repeated full release at exact zero has no write or version increment.
        if ($quantity === 0) {
            return $this->buildStockMutationResult($item, 0, collect(), [], $reservation);
        }

        // Apply the selected release and invalidate the public optimistic item version.
        $codes = $this->resolveReservationReleaseCodes($request, $item, $reservation, $quantity);
        $remainingCodes = $this->remainingReservationCodes($item, $reservation, $codes);
        $remaining = $this->reservationRemainingAfterMutation($item, $reservation, $quantity, $remainingCodes);
        $this->persistLockedReservationBalance($reservation, $remaining, $remainingCodes, 'RELEASED');
        $this->advanceItemVersion($item);

        return $this->buildStockMutationResult($item, $quantity, collect(), $codes, $reservation);
    }

    /** Build copied results from this operation's affected movements and identities only. */
    public function buildStockMutationResult(
        InventoryItem $item,
        int $quantity,
        Collection $movements,
        array $serializedItemCodes = [],
        ?InventoryReservation $reservation = null,
    ): InventoryMutationResult {
        // Validate provenance before exposing public codes from an operation-local collection.
        $movementCodes = $this->affectedMovementCodes($item, $movements);

        return new InventoryMutationResult(
            inventoryItemCode: $item->code,
            updateKey: (int) $item->update_key,
            quantity: InventoryQuantity::format($quantity),
            movementCodes: $movementCodes,
            serializedItemCodes: array_values($serializedItemCodes),
            reservationCode: $reservation?->code,
            remainingQuantity: $reservation === null ? null : (string) $reservation->remaining_quantity,
        );
    }

    /**
     * Run one stock operation atomically with idempotency and optional primary audit.
     * Callbacks must reacquire fresh models each attempt and perform no external side effects.
     * Caller-owned transactions receive one attempt; their outer owner controls any retries.
     * @param callable(int, ?int): array $callback
     */
    public function runStockOperation(
        InventoryOperationContext $context,
        InventoryStockCommand|InventoryReservationReleaseRequest|InventoryLocationCreate|InventoryLocationUpdate $request,
        callable $callback,
    ): array {
        // Only an outermost Inventory operation may retry recognized MySQL deadlocks.
        $tenantId = $this->resolveCurrentTenantId();
        $ownsTransaction = DB::connection()->transactionLevel() === 0;
        $maxAttempts = $ownsTransaction ? 3 : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Recreate reservation records and callback results inside each transaction attempt.
                return $this->executeStockOperationAttempt($context, $request, $callback, $tenantId);
            } catch (IdempotentReplayResponse $replay) {
                // An internal replay must return to its business caller without replacing its response.
                if ($context->owner === InventoryOperationOwner::BusinessCaller) {
                    return $replay->responseData();
                }
                // Public operations preserve the existing HTTP replay response and header.
                throw $replay;
            } catch (Throwable $exception) {
                // Validation/version/idempotency/duplicate failures never qualify for retries.
                if ($attempt < $maxAttempts && $this->isInventoryDeadlock($exception)) {
                    continue;
                }
                $this->logStockOperationFailure($context->operation, $exception);
                throw $exception;
            }
        }

        throw new LogicException('Inventory operation exhausted its attempt loop.');
    }



    /** Validate receipt requirements using already implemented tracking and reference helpers. */
    private function validateReceiptForItem(
        InventoryItem $item,
        InventoryLocation $location,
        InventoryReceiveRequest $request,
    ): int {
        // Destination eligibility must be checked after the shared location lock.
        if (! $location->is_active) {
            throw new InvalidTenantRequest(__('inventory.location_unavailable'));
        }
        if ($request->inventoryItemCode !== null) {
            $this->validateExistingReceiptReferences($request, $item);
        }
        $quantity = InventoryQuantity::parse($request->quantity, $item->tracking_mode);

        // UNIQUE custody considers all locations, using the pinned current checkpoint/tail reader.
        if ($item->tracking_mode === 'UNIQUE') {
            $this->assertUniqueItemHasNoCustody($item);
        }

        return $quantity;
    }

    /** Reuse current checkpoint-backed balances to reject a second UNIQUE receipt in custody. */
    private function assertUniqueItemHasNoCustody(InventoryItem $item): void
    {
        // Derive observed locations from current rows rather than an older location-list snapshot.
        $rows = $this->repository->currentStockRowsForUpdate([(int) $item->id], null);
        $locationIds = array_values(array_unique(array_filter(array_merge(
            $rows['base']->pluck('inventory_location_id')->all(),
            $rows['movements']->pluck('from_location_id')->all(),
            $rows['movements']->pluck('to_location_id')->all(),
        ), static fn ($id): bool => $id !== null)));
        $balances = $this->calculateLockedStockBalances($rows, [(int) $item->id], $locationIds);

        // Non-negative exact location balances mean any positive pair represents existing custody.
        foreach ($balances[$item->id] as $quantity) {
            if ($quantity > 0) {
                throw new InvalidTenantRequest(__('inventory.unique_already_received'));
            }
        }
    }

    /** Append receipt effects and return only the identities created by this receipt. */
    private function appendReceiptMovements(
        int $tenantId,
        InventoryItem $item,
        InventoryLocation $location,
        int $quantity,
        array $identifiers,
        InventoryMovementContext $context,
        string $movementType = 'RECEIVE',
    ): InventoryMutationResult {
        // Existing quantity/UNIQUE receipts append one exact movement.
        if ($item->tracking_mode !== 'SERIALIZED') {
            $movement = $this->appendMovement($tenantId, $item, null, (int) $location->id, InventoryQuantity::format($quantity), $movementType, $context);

            return $this->buildStockMutationResult($item, $quantity, collect([$movement]));
        }

        // Serialized receipts reuse identifier guards and one movement per newly created identity.
        $movements = collect();
        $codes = [];
        foreach ($identifiers as $identifier) {
            $serializedItem = $this->createSerializedItemForReceipt($tenantId, $item, $identifier);
            $movements->push($this->appendMovement($tenantId, $item, null, (int) $location->id, '1.000', $movementType, $context, (int) $serializedItem->id));
            $codes[] = $serializedItem->code;
        }

        return $this->buildStockMutationResult($item, $quantity, $movements, $codes);
    }

    /** Persist a validated sale reservation through the existing repository. */
    private function persistSaleStockReservation(
        int $tenantId,
        InventoryItem $item,
        InventoryLocation $location,
        InventoryReservationRequest $request,
    ): InventoryReservation {
        // Keep code allocation and exact membership inside the caller-owned transaction.
        return $this->repository->createReservation($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_reservations', CarbonImmutable::now()),
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'source_type' => $request->sourceType,
            'source_code' => $request->sourceCode,
            'quantity' => $request->quantity,
            'remaining_quantity' => $request->quantity,
            'serialized_item_codes' => $request->serializedItemCodes,
            'status' => 'ACTIVE'
            ]);
    }

    /** Append conserved move effects using already locked identities and exact quantities. */
    private function appendMoveMovements(
        int $tenantId,
        InventoryLockedStock $stock,
        InventoryMoveRequest $request,
        int $quantity,
        InventoryMovementContext $context,
    ): InventoryMutationResult {
        // Use validated snapshot models; do not repeat their lookup or availability checks.
        $item = $stock->items->get($request->inventoryItemCode);
        $from = $stock->locations->get($request->fromLocationCode);
        $to = $stock->locations->get($request->toLocationCode);
        $movements = collect();
        if ($item->tracking_mode === 'SERIALIZED') {
            foreach ($request->serializedItemCodes as $code) {
                $serializedItem = $stock->serializedItems->get($code);
                $movements->push($this->appendMovement($tenantId, $item, (int) $from->id, (int) $to->id, '1.000', 'MOVE', $context, (int) $serializedItem->id));
            }
        } else {
            $movements->push($this->appendMovement($tenantId, $item, (int) $from->id, (int) $to->id, InventoryQuantity::format($quantity), 'MOVE', $context));
        }

        return $this->buildStockMutationResult($item, $quantity, $movements, $request->serializedItemCodes);
    }

    /** Validate supported caller provenance without enabling deferred workflows. */
    private function validateIssueSourceContext(InventoryIssueRequest $request): void
    {
        // Each enabled business issue identifies its own persisted source record.
        $sourceType = match ($request->operationContext) {
            InventoryIssueContext::Sale => 'SaleDeliveryAllocation',
            InventoryIssueContext::PurchaseReturn => 'PurchaseReturnLine',
            default => null,
        };
        if ($request->operationContext === InventoryIssueContext::PawnRedemption
            || ($sourceType !== null && ($request->sourceType !== $sourceType || ! $request->sourceCode))
            || ($request->operationContext === InventoryIssueContext::Manual
                && ($request->sourceType !== null || $request->sourceCode !== null || $request->reservationCode !== null))) {
            throw new InvalidTenantRequest(__('inventory.issue_context_invalid'));
        }
    }

    /** Append only selected issue records and return their final version and reservation balance. */
    private function appendIssueMovements(
        int $tenantId,
        InventoryLockedStock $stock,
        InventoryIssueRequest|InventoryAdjustmentRequest $request,
        int $quantity,
        InventoryMovementContext $context,
        ?InventoryReservation $reservation,
        string $movementType = 'ISSUE',
    ): InventoryMutationResult {
        // Reuse validated locked models; appendMovement advances the item version.
        $item = $stock->items->get($request->inventoryItemCode);
        $location = $stock->locations->get($request->locationCode);
        $movements = collect();
        if ($item->tracking_mode === 'SERIALIZED') {
            foreach ($request->serializedItemCodes as $code) {
                $serializedItem = $stock->serializedItems->get($code);
                $movements->push($this->appendMovement($tenantId, $item, (int) $location->id, null, '1.000', $movementType, $context, (int) $serializedItem->id));
            }
        } else {
            $movements->push($this->appendMovement($tenantId, $item, (int) $location->id, null, InventoryQuantity::format($quantity), $movementType, $context));
        }

        return $this->buildStockMutationResult($item, $quantity, $movements, $request->serializedItemCodes, $reservation);
    }

    /** Resolve read metadata through the existing lookup with eager reference loading. */
    private function resolveItemForRead(string $code): InventoryItem
    {
        // Preserve named tenant-scoped missing-resource errors.
        $item = $this->repository->findItemByCode($code, withReadReferences: true);
        if ($item === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }

        return $item;
    }

    /** Prepare per-item location maps once for an entire summary page. */
    private function readItemBalanceData(Collection $items): array
    {
        // Empty pages require neither quantity queries nor location metadata.
        if ($items->isEmpty()) {
            return [
                'physical' => [],
                'reserved' => [],
                'locations' => collect()
                ];
        }
        // Reuse existing batch queries instead of calling balances/reservedBalance per item.
        $ids = $items->pluck('id')->all();
        $physical = [];
        foreach ($this->repository->movementBalancesForItems($ids) as $row) {
            $physical[$row->inventory_item_id][$row->location_id] = InventoryQuantity::parseBalance((string) $row->quantity);
        }
        $reserved = [];
        foreach ($this->repository->reservationBalancesForItems($ids) as $row) {
            $reserved[$row->inventory_item_id][$row->inventory_location_id] = InventoryQuantity::parseBalance((string) $row->remaining_quantity);
        }

        return [
            'physical' => $physical,
            'reserved' => $reserved,
            'locations' => $this->repository->locations($this->resolveCurrentTenantId())->keyBy('id')
            ];
    }

    /** Map only observed item/location pairs to the existing typed balance contract. */
    private function buildItemReadLocations(InventoryItem $item, array $balances): array
    {
        // Keyed maps avoid scanning all page balances for every item.
        $physical = $balances['physical'][$item->id] ?? [];
        $reserved = $balances['reserved'][$item->id] ?? [];
        $rows = [];
        foreach (array_keys($physical + $reserved) as $locationId) {
            $location = $balances['locations']->get($locationId);
            if ($location === null) {
                throw new TenantNotFound(__('inventory.location_not_found'));
            }
            $quantity = $physical[$locationId] ?? 0;
            $reservedQuantity = $reserved[$locationId] ?? 0;
            $rows[] = new InventoryLocationBalance(
                location: InventoryLocationDetail::fromModel($location),
                quantity: InventoryQuantity::format($quantity),
                reservedQuantity: InventoryQuantity::format($reservedQuantity),
                availableQuantity: InventoryQuantity::format(max(0, $quantity - $reservedQuantity)),
            );
        }

        return $rows;
    }

    /** Build exact summary totals from already mapped, bounded location balances. */
    private function buildItemReadSummary(InventoryItem $item, array $locations): InventoryItemSummary
    {
        // Browse availability is sellable availability; these snapshots never authorize writes.
        $total = $reserved = $available = 0;
        foreach ($locations as $location) {
            $total = $this->addStockQuantity($total, InventoryQuantity::parseBalance($location->quantity));
            $reserved = $this->addStockQuantity($reserved, InventoryQuantity::parseBalance($location->reservedQuantity));
            if ($location->location->isActive && $location->location->isSellable) {
                $available = $this->addStockQuantity($available, InventoryQuantity::parseBalance($location->availableQuantity));
            }
        }

        // Reuse Pawn's private disk and short-lived URL policy; never expose stored paths.
        $imagePath = $item->getAttribute('collateral_image_path');
        $expiration = CarbonImmutable::now()->addMinutes(5);
        $imageUrl = $this->fileStorageUtility->fileExists($imagePath, 'local')
            ? $this->fileStorageUtility->getTemporaryFileUrl($imagePath, $expiration, 'local')
            : null;

        return new InventoryItemSummary(
            code: $item->code,
            name: $item->name,
            description: $item->description,
            catalogItemCode: $item->catalogItem?->business_code,
            trackingMode: $item->tracking_mode,
            unitCode: $item->unit->code,
            unitName: $item->unit->name,
            quantityScale: (string) $item->quantity_scale,
            totalQuantity: InventoryQuantity::format($total),
            reservedQuantity: InventoryQuantity::format($reserved),
            availableQuantity: InventoryQuantity::format($available),
            updateKey: (int) $item->update_key,
            imageUrl: $imageUrl,
            imageUrlExpiresAt: $imageUrl === null ? null : $expiration->toISOString(),
        );
    }

    /** Map eager-loaded movement provenance without triggering additional queries. */
    private function buildMovementReadDetail(InventoryItem $item, InventoryMovement $movement): InventoryMovementDetail
    {
        // Preserve exact decimals and expose public references instead of database IDs.
        return new InventoryMovementDetail(
            code: $movement->code,
            inventoryItemCode: $item->code,
            serializedItemCode: $movement->serializedItem?->code,
            type: $movement->movement_type,
            quantity: (string) $movement->quantity,
            fromLocation: $movement->fromLocation === null ? null : InventoryLocationDetail::fromModel($movement->fromLocation),
            toLocation: $movement->toLocation === null ? null : InventoryLocationDetail::fromModel($movement->toLocation),
            reason: $movement->reason,
            sourceType: $movement->source_type,
            sourceCode: $movement->source_code,
            createdByCode: $movement->createdBy?->code,
            occurredAt: $movement->occurred_at->toIso8601String(),
        );
    }

    /** Map the repository's joined serialized custody fields without relationship reads. */
    private function buildSerializedItemReadDetail(InventoryItem $item, InventorySerializedItem $serializedItem): InventorySerializedItemDetail
    {
        // A null joined location denotes issued/no-custody stock, not a measurement unit.
        $location = $serializedItem->current_location_code === null ? null : new InventoryLocationDetail(
            code: $serializedItem->current_location_code,
            name: $serializedItem->current_location_name,
            type: $serializedItem->current_location_type,
            isDefault: (bool) $serializedItem->current_location_is_default,
            isActive: (bool) $serializedItem->current_location_is_active,
            isSellable: (bool) $serializedItem->current_location_is_sellable,
            updateKey: (int) $serializedItem->current_location_update_key,
        );

        return new InventorySerializedItemDetail(
            code: $serializedItem->code,
            inventoryItemCode: $item->code,
            identifier: $serializedItem->identifier,
            location: $location,
            isReserved: (bool) $serializedItem->is_reserved,
            isAvailable: $location !== null && ! (bool) $serializedItem->is_reserved,
            latestMovementCode: $serializedItem->latest_movement_code,
        );
    }

    /** Require an item and location selection before taking any locks. */
    private function assertStockSelectionNotEmpty(array $itemCodes, array $locationCodes): void
    {
        // Empty required sets cannot describe a stock operation.
        if ($itemCodes === [] || $locationCodes === []) {
            throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
        }
    }

    /** Lock and validate the complete item set before acquiring other anchors. */
    private function lockSelectedStockItems(array $codes): Collection
    {
        // Missing tenant-scoped rows invalidate the entire selection.
        $items = $this->repository->findItemsByCodesForUpdate($codes)->keyBy('code');
        if ($items->count() !== count($codes)) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }

        return $items;
    }

    /** Acquire shared location locks after all selected item anchors are held. */
    private function lockSelectedStockLocations(array $codes): Collection
    {
        // Keep missing-location handling at the service boundary.
        $locations = $this->repository->findLocationsByCodesForUpdate($codes)->keyBy('code');
        if ($locations->count() !== count($codes)) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }

        return $locations;
    }

    /** Lock live reservations and ensure explicitly supplied references were found. */
    private function lockSelectedStockReservations(array $itemIds, array $codes): Collection
    {
        // Supplied inactive rows remain available for later binding rejection.
        $reservations = $this->repository->reservationsForStockOperation($itemIds, $codes)->keyBy('code');
        if (array_diff($codes, $reservations->keys()->all()) !== []) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }

        return $reservations;
    }

    /** Lock selected serialized identities after reservation locks are acquired. */
    private function lockSelectedSerializedItems(array $itemIds, array $codes): Collection
    {
        // Require all supplied identities to belong to the locked item set.
        $serializedItems = $this->repository->serializedItemsForStockOperation($itemIds, $codes)->keyBy('code');
        if ($serializedItems->count() !== count($codes)) {
            throw new InvalidTenantRequest(__('inventory.serialized_unavailable'));
        }

        return $serializedItems;
    }

    /** Build current custody and exact balances from the already locked selection. */
    private function buildLockedStockSnapshot(
        Collection $items,
        Collection $locations,
        Collection $reservations,
        Collection $serializedItems,
    ): InventoryLockedStock {
        // Read the pinned checkpoint base/tail and latest custody using current reads.
        $itemIds = $items->pluck('id')->all();
        $locationIds = $locations->pluck('id')->all();
        $rows = $this->repository->currentStockRowsForUpdate($itemIds, $locationIds);
        $custody = $this->repository->currentSerializedItemCustodyForUpdate($serializedItems->pluck('id')->all())
            ->keyBy('inventory_serialized_item_id');

        return new InventoryLockedStock(
            items: $items,
            locations: $locations,
            reservations: $reservations,
            serializedItems: $serializedItems,
            custody: $custody,
            balances: $this->calculateLockedStockBalances($rows, $itemIds, $locationIds),
        );
    }

    /** Reject availability validation outside the caller-owned stock transaction. */
    private function assertStockValidationTransaction(): void
    {
        // Locks and current snapshots are only meaningful within their owning transaction.
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inventory availability requires an active transaction.');
        }
    }

    /** Resolve an already locked item and recheck its tenant binding. */
    private function resolveLockedStockItem(InventoryLockedStock $stock, string $code): InventoryItem
    {
        // Do not replace the locked model with a new snapshot query.
        $item = $stock->items->get($code);
        if ($item === null || (int) $item->tenant_id !== $this->resolveCurrentTenantId()) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }

        return $item;
    }

    /** Resolve an already locked location and recheck its tenant binding. */
    private function resolveLockedStockLocation(InventoryLockedStock $stock, string $code): InventoryLocation
    {
        // Preserve the named location error for missing or mismatched records.
        $location = $stock->locations->get($code);
        if ($location === null || (int) $location->tenant_id !== $this->resolveCurrentTenantId()) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }

        return $location;
    }

    /** Validate move destinations/versions and outgoing adjustment direction. */
    private function validateLockedStockOperation(
        InventoryLockedStock $stock,
        InventoryIssueRequest|InventoryReservationRequest|InventoryMoveRequest|InventoryAdjustmentRequest $request,
        InventoryItem $item,
        InventoryLocation $location,
    ): void {
        // A move validates both prelocked locations and its existing optimistic version.
        if ($request instanceof InventoryMoveRequest) {
            $destination = $this->resolveLockedStockLocation($stock, $request->toLocationCode);
            if (! $destination->is_active || (int) $destination->id === (int) $location->id) {
                throw new InvalidTenantRequest(__('inventory.location_unavailable'));
            }
            if ((int) $item->update_key !== $request->updateKey) {
                throw new AlreadyUpdatedException(null);
            }
        }
        if ($request instanceof InventoryAdjustmentRequest && $request->direction !== 'OUT') {
            throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
        }
    }

    /** Enforce sale sellability and the existing non-sale location requirements. */
    private function validateLockedStockLocationEligibility(
        InventoryIssueRequest|InventoryReservationRequest|InventoryMoveRequest|InventoryAdjustmentRequest $request,
        InventoryLocation $location,
    ): void {
        // Sale reservations and sale fulfilment require both active and sellable locations.
        $sale = $request instanceof InventoryReservationRequest
            || ($request instanceof InventoryIssueRequest && $request->operationContext === InventoryIssueContext::Sale);
        if ($sale && (! $location->is_active || ! $location->is_sellable)) {
            throw new InvalidTenantRequest(__('inventory.location_not_sellable'));
        }
        if (! $sale && ! $location->is_active && ! ($request instanceof InventoryIssueRequest)) {
            throw new InvalidTenantRequest(__('inventory.location_unavailable'));
        }
    }

    /** Require originating business references when creating a sale reservation. */
    private function validateStockReservationSource(
        InventoryIssueRequest|InventoryReservationRequest|InventoryMoveRequest|InventoryAdjustmentRequest $request,
    ): void {
        // Reservation creation must carry its originating business reference.
        if ($request instanceof InventoryReservationRequest
            && ($request->sourceType === '' || $request->sourceCode === '')) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }
    }

    /** Select active locked reservations bound to this item and location. */
    private function activeLockedReservationsAtLocation(
        InventoryLockedStock $stock,
        InventoryItem $item,
        InventoryLocation $location,
    ): Collection {
        // Filter existing locked rows without issuing another query.
        return $stock->reservations->filter(
            static fn (InventoryReservation $reservation): bool =>
                (int) $reservation->inventory_item_id === (int) $item->id
                && (int) $reservation->inventory_location_id === (int) $location->id
                && $reservation->status === 'ACTIVE',
        );
    }

    /**
     * Sum exact remaining quantities and validate serialized reservation membership.
     * @return array{0: int, 1: array<string, true>}
     */
    private function summarizeLockedReservations(Collection $reservations, InventoryItem $item): array
    {
        // Build a quantity total and membership map from the same locked records.
        $reserved = 0;
        $reservedCodes = [];
        foreach ($reservations as $reservation) {
            $remaining = InventoryQuantity::parseBalance((string) $reservation->remaining_quantity);
            $codes = $reservation->serialized_item_codes ?? [];
            if ($item->tracking_mode === 'SERIALIZED') {
                $this->validateSerializedItemSelection($item, $remaining, $codes);
                $reservedCodes = $this->addReservedSerializedItemCodes($reservedCodes, $codes);
            } elseif ($codes !== []) {
                throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
            }
            $reserved = $this->addStockQuantity($reserved, $remaining);
        }

        return [$reserved, $reservedCodes];
    }

    /** Merge serialized reservation membership while rejecting overlapping reservations. */
    private function addReservedSerializedItemCodes(array $reservedCodes, array $codes): array
    {
        // Build a membership map once instead of rescanning reservations for each selected item.
        foreach ($codes as $code) {
            if (isset($reservedCodes[$code])) {
                throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
            }
            $reservedCodes[$code] = true;
        }

        return $reservedCodes;
    }

    /** Compare physical availability with the requested quantity using exact thousandths. */
    private function assertLockedStockQuantityAvailable(
        int $physical,
        int $reserved,
        int $quantity,
        ?InventoryReservation $ownReservation,
    ): void {
        // Credit only the already validated originating reservation.
        if ($reserved > $physical) {
            throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
        }
        $credit = $ownReservation === null ? 0 : InventoryQuantity::parseBalance((string) $ownReservation->remaining_quantity);
        if ($quantity > $physical - $reserved + $credit) {
            throw new InvalidTenantRequest(__('inventory.stock_insufficient'));
        }
    }

    /** Validate selected identities against current custody and reservation membership. */
    private function validateLockedSerializedItemAvailability(
        InventoryLockedStock $stock,
        InventoryIssueRequest|InventoryReservationRequest|InventoryMoveRequest|InventoryAdjustmentRequest $request,
        InventoryItem $item,
        InventoryLocation $location,
        array $reservedCodes,
        ?InventoryReservation $ownReservation,
    ): void {
        // Validate complete selected membership and latest custody without per-unit queries.
        if ($item->tracking_mode === 'SERIALIZED') {
            $ownCodes = array_fill_keys($ownReservation?->serialized_item_codes ?? [], true);
            foreach ($request->serializedItemCodes as $code) {
                $serializedItem = $stock->serializedItems->get($code);
                $last = $serializedItem === null ? null : $stock->custody->get($serializedItem->id);
                if ($serializedItem === null
                    || (int) $serializedItem->inventory_item_id !== (int) $item->id
                    || $last === null || (int) $last->to_location_id !== (int) $location->id
                    || ($ownReservation !== null && ! isset($ownCodes[$code]))
                    || (isset($reservedCodes[$code]) && ! isset($ownCodes[$code]))) {
                    throw new InvalidTenantRequest(__('inventory.serialized_unavailable'));
                }
            }
        }
    }

    /** Normalize reference lists without silently accepting malformed values. */
    private function normalizeStockReferenceCodes(array $codes): array
    {
        // Only normalize list references; duplicate command selections are validated separately.
        foreach ($codes as &$code) {
            if (! is_string($code) || trim($code) === '' || mb_strlen(trim($code)) > 40) {
                throw new InvalidTenantRequest(__('inventory.lock_selection_invalid'));
            }
            $code = trim($code);
        }
        unset($code);

        return array_values(array_unique($codes));
    }

    /**
     * Calculate exact balances without materializing every item/location combination.
     * Time: O(I + L + C + B + M + P); extra space: O(I + L + C + P).
     * I/L are selected items/locations, C checkpoint headers, B opening rows,
     * M movements, and P observed item/location pairs; missing pairs represent zero.
     */
    private function calculateLockedStockBalances(array $rows, array $itemIds, array $locationIds): array
    {
        // Build only item buckets and a location membership set, not an I-by-L zero matrix.
        $balances = array_fill_keys($itemIds, []);
        $selectedLocations = array_fill_keys($locationIds, true);

        // Populate observed checkpoint pairs using constant-time header lookups.
        $this->applyLockedStockOpeningBalances($balances, $rows['checkpoints'], $rows['base']);

        // Visit each movement once and update at most two selected location pairs.
        foreach ($rows['movements'] as $movement) {
            $this->applyLockedStockMovement($balances, $selectedLocations, $movement);
        }

        // Validate observed pairs only; absent pairs remain implicit exact zero.
        $this->validateCalculatedStockBalances($balances);

        return $balances;
    }

    /** Populate sparse opening balances without allocating zero pairs for absent history. */
    private function applyLockedStockOpeningBalances(
        array &$balances,
        Collection $checkpoints,
        Collection $openingBalances,
    ): void {
        // Reindex headers once so each opening row resolves its item in constant time.
        $headers = $checkpoints->keyBy('id');
        foreach ($openingBalances as $base) {
            $itemId = $headers->get($base->inventory_monthly_checkpoint_id)->inventory_item_id;
            $balances[$itemId][$base->inventory_location_id] = InventoryQuantity::parseBalance((string) $base->quantity);
        }
    }

    /** Apply one movement's signed deltas to selected sparse location pairs. */
    private function applyLockedStockMovement(
        array &$balances,
        array $selectedLocations,
        InventoryMovement $movement,
    ): void {
        // Parse once and preserve exact signed arithmetic and overflow rejection.
        $quantity = InventoryQuantity::parse((string) $movement->quantity);
        $itemId = $movement->inventory_item_id;
        if ($movement->from_location_id !== null && isset($selectedLocations[$movement->from_location_id])) {
            $locationId = $movement->from_location_id;
            $balances[$itemId][$locationId] = $this->addStockQuantity($balances[$itemId][$locationId] ?? 0, -$quantity);
        }
        if ($movement->to_location_id !== null && isset($selectedLocations[$movement->to_location_id])) {
            $locationId = $movement->to_location_id;
            $balances[$itemId][$locationId] = $this->addStockQuantity($balances[$itemId][$locationId] ?? 0, $quantity);
        }
    }

    /** Validate each observed pair once; nested buckets together contain only P pairs. */
    private function validateCalculatedStockBalances(array $balances): void
    {
        // Reject final negative or oversized quantities without clamping ledger inconsistencies.
        foreach ($balances as $locations) {
            foreach ($locations as $quantity) {
                if ($quantity < 0) {
                    throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
                }
                InventoryQuantity::format($quantity);
            }
        }
    }

    /** Add signed thousandths without allowing PHP integer overflow to become float arithmetic. */
    private function addStockQuantity(int $left, int $right): int
    {
        // Reject overflow before PHP promotes an integer expression to a float.
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new InvalidTenantRequest(__('inventory.quantity_out_of_range'));
        }

        return $left + $right;
    }

    /** Bind an issue reservation to its original business source, not its movement source. */
    private function validateLockedIssueReservation(
        InventoryLockedStock $stock,
        InventoryIssueRequest $request,
        InventoryItem $item,
        InventoryLocation $location,
        int $quantity,
    ): ?InventoryReservation {
        // Sale fulfilment requires a supplied reservation; manual/non-sale issues may omit one.
        if ($request->reservationCode === null) {
            if ($request->operationContext === InventoryIssueContext::Sale) {
                throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
            }
            return null;
        }
        $reservation = $stock->reservations->get($request->reservationCode);
        if ($reservation === null
            || (int) $reservation->tenant_id !== $this->resolveCurrentTenantId()
            || (int) $reservation->inventory_item_id !== (int) $item->id
            || (int) $reservation->inventory_location_id !== (int) $location->id
            || $reservation->status !== 'ACTIVE'
            || $request->expectedReservationSourceType === null || $request->expectedReservationSourceType === ''
            || $request->expectedReservationSourceCode === null || $request->expectedReservationSourceCode === ''
            || $reservation->source_type !== $request->expectedReservationSourceType
            || $reservation->source_code !== $request->expectedReservationSourceCode
            || InventoryQuantity::parseBalance((string) $reservation->remaining_quantity) < $quantity) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }

        return $reservation;
    }


    /** Resolve a release reservation and orchestrate binding and stored-state validation. */
    private function validateLockedReleaseReservation(
        InventoryLockedStock $stock,
        InventoryReservationReleaseRequest $request,
        InventoryItem $item,
        InventoryLocation $location,
    ): InventoryReservation {
        // A supplied missing reference must never become an unreserved release.
        $reservation = $stock->reservations->get($request->reservationCode);
        if ($reservation === null) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }

        // Check original source separately from active/completed membership state.
        $this->assertReleaseReservationBinding($reservation, $request, $item, $location);
        $this->assertReleaseReservationState($reservation, $request, $item);

        return $reservation;
    }

    /** Bind a release to its locked tenant, item, location, and originating source. */
    private function assertReleaseReservationBinding(
        InventoryReservation $reservation,
        InventoryReservationReleaseRequest $request,
        InventoryItem $item,
        InventoryLocation $location,
    ): void {
        // Movement provenance cannot substitute for the reservation's original source.
        if ((int) $reservation->tenant_id !== $this->resolveCurrentTenantId()
            || (int) $reservation->inventory_item_id !== (int) $item->id
            || (int) $reservation->inventory_location_id !== (int) $location->id
            || $reservation->source_type !== $request->expectedSourceType
            || $reservation->source_code !== $request->expectedSourceCode) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }
    }

    /** Permit repeat full release only for consistent completed zero-quantity records. */
    private function assertReleaseReservationState(
        InventoryReservation $reservation,
        InventoryReservationReleaseRequest $request,
        InventoryItem $item,
    ): void {
        // Stored serialized quantity must equal its remaining code count before mutation.
        $remaining = InventoryQuantity::parseBalance((string) $reservation->remaining_quantity);
        $this->validateSerializedItemSelection($item, $remaining, $reservation->serialized_item_codes ?? []);
        if ($reservation->status === 'ACTIVE' && $remaining === 0) {
            throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
        }
        if ($reservation->status !== 'ACTIVE'
            && (! $request->releasesAll() || $remaining !== 0
                || ! in_array($reservation->status, ['RELEASED', 'CONSUMED'], true))) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }
    }

    /** Resolve exact release quantity; serialized selections may provide their own count. */
    private function resolveReservationReleaseQuantity(
        InventoryReservationReleaseRequest $request,
        InventoryItem $item,
        InventoryReservation $reservation,
    ): int {
        // A full release includes every remaining code and can validly be an exact-zero no-op.
        $remaining = InventoryQuantity::parseBalance((string) $reservation->remaining_quantity);
        if ($request->releasesAll()) {
            return $remaining;
        }
        $quantity = $request->quantity === null
            ? InventoryQuantity::parse(count($request->serializedItemCodes), $item->tracking_mode)
            : InventoryQuantity::parse($request->quantity, $item->tracking_mode);
        if ($quantity > $remaining) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }

        return $quantity;
    }

    /** Require exact serialized codes for partial release and all remaining codes for full release. */
    private function resolveReservationReleaseCodes(
        InventoryReservationReleaseRequest $request,
        InventoryItem $item,
        InventoryReservation $reservation,
        int $quantity,
    ): array {
        // Reuse exact tracking/count/duplicate validation for full and selected releases.
        $codes = $request->releasesAll() ? ($reservation->serialized_item_codes ?? []) : $request->serializedItemCodes;
        $this->validateSerializedItemSelection($item, $quantity, $codes);

        return $codes;
    }

    /** Remove exactly the consumed/released codes, preserving the remaining stored order. */
    private function remainingReservationCodes(
        InventoryItem $item,
        InventoryReservation $reservation,
        array $selectedCodes,
    ): array {
        // Nonserialized reservations must never carry identity membership.
        $storedCodes = $reservation->serialized_item_codes ?? [];
        if ($item->tracking_mode !== 'SERIALIZED') {
            if ($storedCodes !== [] || $selectedCodes !== []) {
                throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
            }
            return [];
        }

        // Membership sets give linear subtraction without repeated per-code searches.
        $stored = array_fill_keys($storedCodes, true);
        $selected = array_fill_keys($selectedCodes, true);
        if (count($stored) !== count($storedCodes) || count($selected) !== count($selectedCodes)
            || array_diff_key($selected, $stored) !== []) {
            throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
        }

        return array_values(array_filter($storedCodes, static fn (string $code): bool => ! isset($selected[$code])));
    }

    /** Keep decimal remaining quantity identical to serialized remaining membership. */
    private function reservationRemainingAfterMutation(
        InventoryItem $item,
        InventoryReservation $reservation,
        int $quantity,
        array $remainingCodes,
    ): int {
        // Reject excess mutation rather than clamping a negative balance to zero.
        $remaining = InventoryQuantity::parseBalance((string) $reservation->remaining_quantity) - $quantity;
        if ($remaining < 0) {
            throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
        }
        if ($item->tracking_mode === 'SERIALIZED' && count($remainingCodes) * InventoryQuantity::SCALE !== $remaining) {
            throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
        }

        return $remaining;
    }

    /** Persist exact reservation state through the existing repository in the owning transaction. */
    private function persistLockedReservationBalance(
        InventoryReservation $reservation,
        int $remaining,
        array $remainingCodes,
        string $completedStatus,
    ): void {
        // Status changes only at exact zero; no 0.001 tolerance is used.
        $updated = $this->repository->updateReservation($reservation, [
            'remaining_quantity' => InventoryQuantity::format($remaining),
            'serialized_item_codes' => $remainingCodes,
            'status' => $remaining === 0 ? $completedStatus : 'ACTIVE'
            ]);
        $reservation->setRawAttributes($updated->getAttributes(), true);
    }

    /** Copy affected movement references without loading the item's full history. */
    private function affectedMovementCodes(InventoryItem $item, Collection $movements): array
    {
        // Result construction must not expose another tenant's item or movements.
        if ((int) $item->tenant_id !== $this->resolveCurrentTenantId()) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }
        $codes = [];
        foreach ($movements as $movement) {
            if (! $movement instanceof InventoryMovement
                || (int) $movement->tenant_id !== (int) $item->tenant_id
                || (int) $movement->inventory_item_id !== (int) $item->id) {
                throw new InvalidTenantRequest(__('inventory.item_unavailable'));
            }
            $codes[] = $movement->code;
        }

        return array_values(array_unique($codes));
    }

    /** Reserve, execute, audit, and complete within one database transaction attempt. */
    private function executeStockOperationAttempt(
        InventoryOperationContext $context,
        InventoryStockCommand|InventoryReservationReleaseRequest|InventoryLocationCreate|InventoryLocationUpdate $request,
        callable $callback,
        int $tenantId,
    ): array {
        // Nest within a business caller's transaction without committing its work.
        return DB::transaction(function () use ($context, $request, $callback, $tenantId): array {
            $payload = $request->toArray();
            unset($payload['idempotency_key']);
            $record = $this->idempotencyService->reserveOptional($context->operation, $request->idempotencyKey, $payload);
            if ($record !== null && $this->idempotencyService->isReplay($record)) {
                $this->idempotencyService->replay($record);
            }

            // Primary audit and idempotency completion roll back with all stock writes.
            $result = $callback($tenantId, $record?->id);
            $this->recordStockOperationAudit($context, $result);
            if ($record !== null) {
                $this->idempotencyService->markCompleted($record, $context->responseCode, ['data' => $result]);
            }

            return $result;
        }, 1);
    }

    /** Record only a public Inventory operation's primary audit inside its stock transaction. */
    private function recordStockOperationAudit(InventoryOperationContext $context, array $result): void
    {
        // Business callers retain ownership of their existing Sales/Purchasing/Pawn audits.
        if ($context->owner === InventoryOperationOwner::PublicInventory) {
            $this->recordAudit($context->auditAction, $context->auditTargetType, $context->auditTargetId, [
                'operation' => $context->operation,
                'result' => $result
                ]);
        }
    }

    /** Recognize MySQL deadlock 1213 through Laravel's nested exception chain only. */
    private function isInventoryDeadlock(Throwable $exception): bool
    {
        // Exclude lock timeouts, generic serialization errors, and arbitrary message matching.
        do {
            if (($exception instanceof QueryException || $exception instanceof \PDOException)
                && (int) ($exception->errorInfo[1] ?? 0) === 1213) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    /** Log the final failure without replacing the original domain/database exception. */
    private function logStockOperationFailure(string $operation, Throwable $exception): void
    {
        // Diagnostics are outside rolled-back stock work; they do not create failed idempotency rows.
        try {
            app(OperationLogger::class)->failed($operation, $exception);
        } catch (Throwable) {
            // A logging channel failure must not mask the operation's original failure.
        }
    }

    /** Acquire the current location and enforce the existing optimistic edit version. */
    private function lockLocationForEdit(InventoryLocationUpdate $request): InventoryLocation
    {
        // Named missing-resource and stale-edit responses remain at the service boundary.
        $location = $this->repository->findLocationByCode($request->locationCode, true);
        if ($location === null) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }
        if ((int) $location->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException(null);
        }

        return $location;
    }

    /** Validate replacement names and restrictions before applying any editable fields. */
    private function validateLocationEdit(InventoryLocation $location, InventoryLocationUpdate $request): void
    {
        // Reuse the database-collation name guard while excluding the edited location.
        if ($request->name !== null) {
            $this->assertLocationNameAvailable($request->name, (int) $location->id);
        }

        // Keep the default active and protect operations with outstanding reservations.
        $this->assertLocationRestrictionsCanChange($location, $request);
        if ($request->isActive === false) {
            $this->assertLocationHasNoStock($location);
        }
    }

    /** Reject location restrictions that invalidate its default or active sale commitments. */
    private function assertLocationRestrictionsCanChange(
        InventoryLocation $location,
        InventoryLocationUpdate $request,
    ): void {
        // The default location cannot be deactivated even when empty.
        if ($location->is_default && $request->isActive === false) {
            throw new InvalidTenantRequest(__('inventory.default_location_must_remain_active'));
        }

        // Existing sale reservations require an active, sellable fulfilment location.
        if (($request->isActive === false || $request->isSellable === false)
            && $this->repository->locationHasActiveReservationsForUpdate((int) $location->id)) {
            throw new InvalidTenantRequest(__('inventory.location_has_active_reservations'));
        }
    }

    /** Check each item's net custody separately before allowing location deactivation. */
    private function assertLocationHasNoStock(InventoryLocation $location): void
    {
        // A one-off administrative check reads current source history in one query.
        $rows = $this->repository->locationMovementRowsForRestrictionCheck((int) $location->id);
        $balances = [];
        foreach ($rows as $movement) {
            $quantity = InventoryQuantity::parse((string) $movement->quantity);
            $delta = ((int) $movement->to_location_id === (int) $location->id ? $quantity : 0)
                - ((int) $movement->from_location_id === (int) $location->id ? $quantity : 0);
            $itemId = $movement->inventory_item_id;
            $balances[$itemId] = $this->addStockQuantity($balances[$itemId] ?? 0, $delta);
        }

        // Do not let one item's negative ledger cancel another item's positive stock.
        foreach ($balances as $quantity) {
            if ($quantity < 0) {
                throw new InvalidTenantRequest(__('inventory.stock_inconsistent'));
            }
            if ($quantity > 0) {
                throw new InvalidTenantRequest(__('inventory.location_has_stock'));
            }
        }
    }

    /** Apply partial fields and advance the location version through the existing conditional write. */
    private function persistLocationEdit(InventoryLocation $location, InventoryLocationUpdate $request): void
    {
        // Null remains unchanged; explicit false flags must survive filtering.
        $attributes = array_filter([
            'name' => $request->name,
            'type' => $request->type,
            'is_active' => $request->isActive,
            'is_sellable' => $request->isSellable
            ], static fn (mixed $value): bool => $value !== null);

        // Exactly one expected-version match is required; otherwise the wrapper rolls back.
        if (! $this->repository->updateLocation($location, $attributes, $request->updateKey)) {
            throw new AlreadyUpdatedException(null);
        }
    }

    /** Validate supported location types for HTTP and trusted internal callers. */
    private function validateLocationType(string $type): void
    {
        // DTO construction normalizes case; allow only the existing location categories.
        if (! in_array($type, [
            'SHOP',
            'STORAGE',
            'VAULT',
            'DISPLAY',
            'LENDER',
            'OTHER'
            ], true)) {
            throw new InvalidTenantRequest(__('inventory.location_type_invalid'));
        }
    }

    /** Hold the existing default location as the per-tenant location creation anchor. */
    private function lockLocationCreationNamespace(): void
    {
        // Tenant bootstrap creates this row; do not silently create a replacement here.
        if ($this->repository->lockDefaultLocationForCreation() === null) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }
    }

    /** Allocate the public code and persist explicit initial location state. */
    private function persistNewInventoryLocation(int $tenantId, InventoryLocationCreate $request): InventoryLocation
    {
        // Keep code allocation inside the same transaction as duplicate guard, insert, and audit.
        $code = $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_locations', CarbonImmutable::now());

        return $this->repository->createLocation($tenantId, [
            'code' => $code,
            'name' => $request->name,
            'type' => $request->type,
            'is_default' => false,
            'is_active' => true,
            'is_sellable' => $request->isSellable,
            'update_key' => 0
            ]);
    }

    /** Guard location names consistently before create or rename operations. */
    private function assertLocationNameAvailable(string $name, ?int $exceptId = null): void
    {
        // Validate normalized input before asking the tenant-scoped database comparison.
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidTenantRequest(__('inventory.location_name_invalid'));
        }
        if ($this->repository->locationNameExists($name, $exceptId)) {
            throw new DuplicateValueFound(__('inventory.location_name_in_use'));
        }
    }

    private function runIdempotent(
        string $operation,
        InventoryStockCommand $request,
        callable $callback,
        int $responseCode = 200,
    ): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        // Serialize only at the idempotency boundary; service logic uses typed request properties.
        $payload = $request->toArray();
        unset($payload['idempotency_key']);
        $record = $this->idempotencyService->reserveOptional($operation, $request->idempotencyKey, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) {
            $this->idempotencyService->replay($record);
        }

        try {
            $result = DB::transaction(function () use ($callback, $tenantId, $record, $responseCode): array {
                $result = $callback($tenantId, $record?->id);
                if ($record !== null) {
                    $this->idempotencyService->markCompleted($record, $responseCode, ['data' => $result]);
                }
                return $result;
            });
            return $result;
        } catch (Throwable $exception) {
            if ($record !== null) {
                $this->idempotencyService->markFailed($record);
            }
            throw $exception;
        }
    }

    /** Validate receipt metadata against an existing item without silently ignoring conflicts. */
    private function validateExistingReceiptReferences(InventoryReceiveRequest $request, InventoryItem $item): void
    {
        // Require any explicit tracking override to match the resolved Inventory item.
        if ($request->trackingMode !== null && $request->trackingMode !== $item->tracking_mode) {
            throw new InvalidTenantRequest(__('inventory.tracking_mismatch'));
        }

        // Resolve optional measurement-unit references through the existing Catalog service.
        if ($request->unitCode !== null) {
            $unitId = $this->catalogTaxonomyService->unitIdForCodeInTenant($this->resolveCurrentTenantId(), $request->unitCode);
            if ($unitId === null) {
                throw new InvalidTenantRequest(__('inventory.unit_unavailable'));
            }
            if ((int) $item->unit_id !== $unitId) {
                throw new InvalidTenantRequest(__('inventory.unit_mismatch'));
            }
        }

        // Reject a supplied Catalog reference that is unavailable or belongs to another item.
        if ($request->catalogItemCode !== null) {
            $catalogItem = $this->catalogItemService->availableForInventoryByCode($request->catalogItemCode);
            if ($catalogItem === null || (int) $item->catalog_item_id !== (int) $catalogItem['id']) {
                throw new InvalidTenantRequest(__('inventory.catalog_unavailable'));
            }
        }
    }

    private function createInventoryItem(int $tenantId, InventoryReceiveRequest $request): InventoryItem
    {
        $catalogItem = isset($request->catalogItemCode)
            ? $this->catalogItemService->availableForInventoryByCode((string) $request->catalogItemCode)
            : null;
        if (isset($request->catalogItemCode) && $catalogItem === null) {
            throw new InvalidTenantRequest(__('inventory.catalog_unavailable'));
        }

        if ($catalogItem !== null
            && isset($request->trackingMode)
            && strtoupper((string) $request->trackingMode) !== $catalogItem['tracking_mode']) {
            throw new InvalidTenantRequest(__('inventory.tracking_mismatch'));
        }
        if ($catalogItem !== null
            && isset($request->unitCode)
            && (string) $request->unitCode !== (string) $catalogItem['unit_code']) {
            throw new InvalidTenantRequest(__('inventory.unit_mismatch'));
        }

        $unitCode = (string) ($request->unitCode ?? $catalogItem['unit_code'] ?? 'unit');
        $unitId = $this->catalogTaxonomyService->unitIdForCodeInTenant($tenantId, $unitCode);
        if ($unitId === null) {
            throw new InvalidTenantRequest(__('inventory.unit_unavailable'));
        }

        $mode = strtoupper((string) ($request->trackingMode ?? $catalogItem['tracking_mode'] ?? 'QUANTITY'));
        if (! in_array($mode, ['UNIQUE', 'SERIALIZED', 'QUANTITY'], true)) {
            throw new InvalidTenantRequest(__('inventory.tracking_invalid'));
        }
        // Freeform serialized items need a valid measurement unit, but no Catalog record.
        InventoryQuantity::parse($request->quantity, $mode);
        $name = trim((string) ($request->name ?? $catalogItem['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidTenantRequest(__('inventory.name_required'));
        }

        return $this->repository->createItem($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_items', CarbonImmutable::now()),
            'catalog_item_id' => $catalogItem['id'] ?? null,
            'unit_id' => $unitId,
            'tracking_mode' => $mode,
            'name' => $name,
            'description' => $request->description ?? $catalogItem['description'] ?? null,
            'quantity_scale' => 1,
        ]);
    }

    /** Guard and create one serialized identity inside its owning receipt transaction. */
    private function createSerializedItemForReceipt(int $tenantId, InventoryItem $item, ?string $identifier): InventorySerializedItem
    {
        // Check each write with database collation, including earlier inserts in this batch.
        if ($identifier !== null && $this->repository->serializedItemIdentifierExists($identifier)) {
            throw new DuplicateValueFound(__('inventory.identifier_in_use'));
        }

        // Generated public codes retain their existing allocation and uniqueness constraints.
        return $this->repository->createSerializedItem(
            $tenantId,
            (int) $item->id,
            $identifier,
            $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_serialized_items', CarbonImmutable::now()),
        );
    }

    private function validateQuantityForMode(InventoryItem $item, mixed $rawQuantity): string
    {
        // Validate the original input before any float conversion, using exact tracking rules.
        return InventoryQuantity::format(InventoryQuantity::parse($rawQuantity, $item->tracking_mode));
    }

    private function validateSerializedReceive(
        int $tenantId,
        InventoryItem $item,
        string|int|float $quantity,
        array $identifiers,
    ): array
    {
        if ($item->tracking_mode !== 'SERIALIZED') {
            if ($identifiers !== []) {
                throw new InvalidTenantRequest(__('inventory.identifiers_not_allowed'));
            }
            return [];
        }
        // Compare the exact whole-unit count without rounding the received quantity.
        $quantityUnits = InventoryQuantity::parse($quantity, 'SERIALIZED');
        if (count($identifiers) !== intdiv($quantityUnits, InventoryQuantity::SCALE)) {
            throw new InvalidTenantRequest(__('inventory.identifier_count'));
        }
        $normalizedIdentifiers = array_map(
            static function (mixed $value): ?string {
                // Permit absent labels while rejecting coercion of non-string identifiers.
                if ($value !== null && (! is_string($value) || mb_strlen(trim($value)) > 120)) {
                    throw new InvalidTenantRequest(__('inventory.identifier_invalid'));
                }
                $identifier = $value === null ? null : trim($value);

                return $identifier === '' ? null : $identifier;
            },
            $identifiers,
        );
        // Exclude null only so the literal identifier "0" participates in uniqueness checks.
        $providedIdentifiers = array_values(array_filter($normalizedIdentifiers, static fn (?string $identifier): bool => $identifier !== null));
        if (count(array_unique($providedIdentifiers)) !== count($providedIdentifiers)) {
            throw new DuplicateValueFound(__('inventory.identifier_duplicate'));
        }
        // Batch database checks; the unique constraint remains authoritative for races/collation.
        if ($this->repository->existingSerializedItemIdentifiers($providedIdentifiers)->isNotEmpty()) {
            throw new DuplicateValueFound(__('inventory.identifier_in_use'));
        }
        return $normalizedIdentifiers;
    }

    /** Validate selected-unit shape; actual tenant/item/custody membership is checked under locks. */
    private function validateSerializedItemSelection(InventoryItem $item, int $quantityUnits, array $unitCodes): void
    {
        // Non-serialized stock cannot carry serialized identities.
        if ($item->tracking_mode !== 'SERIALIZED') {
            if ($unitCodes !== []) {
                throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
            }

            return;
        }

        // Validate strings before normalization so arrays and scalar coercions cannot become codes.
        $normalizedCodes = [];
        foreach ($unitCodes as $code) {
            if (! is_string($code) || trim($code) === '' || mb_strlen(trim($code)) > 40) {
                throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
            }
            $normalizedCodes[] = trim($code);
        }

        // Count exact whole units and reject duplicate normalized selections.
        if ($quantityUnits % InventoryQuantity::SCALE !== 0
            || count($normalizedCodes) !== intdiv($quantityUnits, InventoryQuantity::SCALE)
            || count(array_unique($normalizedCodes)) !== count($normalizedCodes)) {
            throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
        }
    }

    private function assertAvailable(int $tenantId, InventoryItem $item, int $locationId, string|int|float $quantity, array $unitIds, ?string $reservationCode = null): void
    {
        // Reuse tracking and selected-unit validation before the later availability decision.
        $this->validateSerializedItemSelection($item, InventoryQuantity::parse($quantity, $item->tracking_mode), $unitIds);
        if ($item->tracking_mode === 'SERIALIZED') {
            $units = $this->lockedSerializedItemsByCodes($tenantId, $item, $unitIds);
            if ($units->count() !== count($unitIds)) {
                throw ValidationException::withMessages(['serialized_item_codes' => ['One or more serialized items are unavailable.']]);
            }
            $reservedCodes = $this->repository->activeReservedSerializedItemCodes($tenantId, $item->id, $locationId);
            // TenantContext scopes reservation membership to the current tenant.
            $ownReservationCodes = $reservationCode === null ? [] : ($this->repository->reservationByCode($reservationCode)?->serialized_item_codes ?? []);
            if (array_diff($unitIds, $ownReservationCodes) !== [] && $reservationCode !== null) {
                throw ValidationException::withMessages(['serialized_item_codes' => ['The selected units do not belong to this reservation.']]);
            }
            foreach ($units as $unit) {
                $last = \App\Models\InventoryModule\InventoryMovement::query()
                    ->where('tenant_id', $tenantId)->where('inventory_serialized_item_id', $unit->id)->latest('id')->first();
                if ((int) ($last?->to_location_id ?? 0) !== $locationId) {
                    throw ValidationException::withMessages(['serialized_item_codes' => ['A selected unit is not at this location.']]);
                }
                if (in_array($unit->code, $reservedCodes, true) && ! in_array($unit->code, $ownReservationCodes, true)) {
                    throw ValidationException::withMessages(['serialized_item_codes' => ['A selected unit is reserved for another sale.']]);
                }
            }
            return;
        }
        $available = (float) $this->repository->balance($tenantId, $item->id, $locationId)
            - $this->repository->reservedBalance($tenantId, $item->id, $locationId);
        if ($reservationCode !== null) {
            // TenantContext scopes the supplied reservation to the current tenant.
            $reservation = $this->repository->reservationByCode($reservationCode);
            $available += (float) ($reservation?->remaining_quantity ?? 0);
        }
        if ($available + 0.001 < $quantity) {
            throw ValidationException::withMessages(['quantity' => ['There is not enough available inventory at this location.']]);
        }
    }

    private function lockedSerializedItemsByCodes(int $tenantId, InventoryItem $item, array $unitCodes)
    {
        // Resolve serialized items by business code while keeping database IDs internal to the movement ledger.
        return $this->repository->findSerializedItemsByCodesForUpdate($item->id, $unitCodes);
    }

    private function activeLocationByCode(
        int $tenantId,
        string $locationCode,
        bool $lock,
        bool $requireActive = true,
        bool $requireSellable = false,
    ): InventoryLocation
    {
        // Resolve a tenant-owned location using its business code.
        $location = $this->repository->findLocationByCode($locationCode, $lock);
        if ($location === null) {
            throw new TenantNotFound(__('inventory.location_not_found'));
        }
        if ($requireActive && ! $location->is_active) {
            throw new InvalidTenantRequest(__('inventory.location_unavailable'));
        }
        // Sellability is operation-specific; internal non-sale issues can use inactive custody.
        if ($requireSellable && (! $location->is_active || ! $location->is_sellable)) {
            throw new InvalidTenantRequest(__('inventory.location_not_sellable'));
        }
        return $location;
    }

    private function lockedItemByCode(int $tenantId, string $itemCode): InventoryItem
    {
        // Resolve and lock the tenant-owned Inventory item using its public business code.
        $item = $this->repository->findItemByCode($itemCode, true);
        if ($item === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }
        return $item;
    }

    private function appendMovement(
        int $tenantId,
        InventoryItem $item,
        ?int $from,
        ?int $to,
        string $quantity,
        string $type,
        InventoryMovementContext $context,
        ?int $unitId = null,
    ): InventoryMovement
    {
        // Normalize exact decimal text before persistence; future callers pass no float quantities.
        $quantity = InventoryQuantity::format(InventoryQuantity::parse($quantity, $item->tracking_mode));
        // The repository assigns TenantContext, recording time, and the checkpoint anchor lock.
        $movement = $this->repository->createMovement([
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_movements', CarbonImmutable::now()),
            'inventory_item_id' => $item->id,
            'inventory_serialized_item_id' => $unitId,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'quantity' => $quantity,
            'movement_type' => $type,
            'reason' => $context->reason,
            'source_type' => $context->sourceType,
            'source_code' => $context->sourceCode,
            'idempotency_record_id' => $context->idempotencyRecordId,
            'created_by' => Auth::guard('tenantuser')->id(),
            'occurred_at' => now(),
        ]);
        // The repository returns null without inserting if the item anchor no longer exists.
        if ($movement === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
        }
        // Physical changes invalidate stale move requests within the same transaction.
        $this->advanceItemVersion($item);

        return $movement;
    }

    /** Update the version through the repository after a caller-owned state mutation. */
    private function advanceItemVersion(InventoryItem $item): void
    {
        // A failed expected-version write must roll back the owning stock transaction.
        if (! $this->repository->incrementItemVersion($item)) {
            throw new AlreadyUpdatedException(null);
        }
    }

    private function itemResource(int $tenantId, InventoryItem $item): array
    {
        $balances = $this->repository->balances($tenantId, $item->id);
        $locations = $this->repository->locations($tenantId)->keyBy('id');
        $locationRows = [];
        foreach ($balances as $locationId => $quantity) {
            if ($quantity > 0) {
                $reserved = $this->repository->reservedBalance($tenantId, $item->id, (int) $locationId);
                $locationRows[] = [
                    'location_id' => (int) $locationId,
                    'location_code' => $locations->get($locationId)?->code,
                    'location' => $locations->get($locationId)?->name,
                    'quantity' => number_format($quantity, 3, '.', ''),
                    'reserved_quantity' => number_format($reserved, 3, '.', ''),
                    'available_quantity' => number_format(max(0, $quantity - $reserved), 3, '.', ''),
                    'is_sellable' => (bool) $locations->get($locationId)?->is_sellable,
                ];
            }
        }
        // Capture the parent item for tenant/item-bound reservation membership checks.
        $serializedItems = $this->repository->serializedItems($tenantId, $item->id)->map(function ($unit) use ($tenantId, $item): array {
            $lastMovement = $this->repository->latestSerializedItemMovement($tenantId, $unit->id);
            return [
                'code' => $unit->code,
                'identifier' => $unit->identifier,
                'location_code' => $lastMovement?->toLocation?->code,
                'location' => $lastMovement?->toLocation?->name,
                'is_reserved' => in_array($unit->code, $this->repository->activeReservedSerializedItemCodes($tenantId, $item->id, (int) ($lastMovement?->to_location_id ?? 0)), true),
            ];
        })->all();
        return [
            'code' => $item->code,
            'update_key' => (int) $item->update_key,
            'name' => $item->name,
            'description' => $item->description,
            'catalog_item_code' => $item->catalogItem?->business_code,
            'tracking_mode' => $item->tracking_mode,
            'unit_code' => $item->unit?->code,
            'unit' => $item->unit?->name,
            'total_quantity' => number_format(array_sum($balances), 3, '.', ''),
            'available_quantity' => number_format(collect($balances)->keys()->filter(fn ($locationId) => $locations->get($locationId)?->is_sellable)->sum(fn ($locationId) => max(0, ($balances[$locationId] ?? 0) - $this->repository->reservedBalance($tenantId, $item->id, (int) $locationId))), 3, '.', ''),
            'locations' => $locationRows,
            'serialized_items' => $serializedItems,
        ];
    }

    private function locationResource(InventoryLocation $location): array
    {
        return [
            'code' => $location->code,
            'update_key' => (int) $location->update_key,
            'name' => $location->name,
            'type' => $location->type,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
            'is_sellable' => $location->is_sellable,
        ];
    }
}
