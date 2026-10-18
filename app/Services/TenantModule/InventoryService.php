<?php

namespace App\Services\TenantModule;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\InventoryModule\InventorySerializedItem;
use App\Models\PawnModule\PawnCollateralItem;
use App\DataObjects\RequestObjects\InventoryStockCommand;
use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\TenantNotFound;
use App\Support\InventoryQuantity;
use App\Repository\InventoryRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\TenantIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryService extends BaseTenantService
{
    public function __construct(
        private InventoryRepository $repository,
        private CatalogItemService $catalogItemService,
        private CatalogTaxonomyService $catalogTaxonomyService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $tableIdGenerationService,
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

    public function createLocation(array $data): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        // Keep the pre-write guard, code allocation, and insert in their owning transaction.
        return DB::transaction(function () use ($tenantId, $data): array {
            $data['name'] = trim($data['name']);
            $this->assertLocationNameAvailable($data['name']);
            $data['code'] = $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_locations', CarbonImmutable::now());
            $location = $this->repository->createLocation($tenantId, $data);

            return $this->locationResource($location);
        });
    }

    public function updateLocationByCode(string $locationCode, array $data): array
    {
        // Resolve and lock the location by its public tenant-scoped business code.
        $tenantId = $this->resolveCurrentTenantId();
        return DB::transaction(function () use ($tenantId, $locationCode, $data): array {
            // Resolve the location within TenantContext and retain the exclusive edit lock.
            $location = $this->repository->findLocationByCode($locationCode, true);
            // Return a named tenant-owned resource error instead of a generic HTTP abort.
            if ($location === null) {
                throw new TenantNotFound(__('inventory.location_not_found'));
            }
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                // Exclude the current location while checking the replacement name.
                $this->assertLocationNameAvailable($data['name'], (int) $location->id);
            }
            if ($location->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => ['The default Main Shop location cannot be deactivated.']]);
            }
            return $this->locationResource($this->repository->updateLocation($location, $data));
        });
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

    public function receive(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.receive', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $location = $this->activeLocationByCode($tenantId, (string) $data['location_code'], true);
                $item = isset($data['inventory_item_code'])
                    ? $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code'])
                    : $this->createInventoryItem($tenantId, $data);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                if ($item->tracking_mode === 'UNIQUE'
                    && array_sum($this->repository->balances($tenantId, $item->id)) > 0) {
                    throw ValidationException::withMessages([
                        'inventory_item_code' => ['This unique item has already been received.'],
                    ]);
                }
                $serializedItemIdentifiers = $this->validateSerializedReceive(
                    $tenantId,
                    $item,
                    $quantity,
                    $data['serialized_item_identifiers'] ?? [],
                );

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($serializedItemIdentifiers as $identifier) {
                        // Recheck the identifier immediately before its write.
                        $unit = $this->createSerializedItemForReceipt($tenantId, $item, $identifier);
                        $this->appendMovement($tenantId, $item, null, $location->id, 1, 'RECEIVE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, null, $location->id, $quantity, 'RECEIVE', $data);
                }

                return $this->itemResource($tenantId, $item->refresh());
            });
        }, 201);
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
    public function receiveSaleReturn(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.receive.sale_return', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            return DB::transaction(function () use ($tenantId, $recordId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $location = $this->activeLocationByCode($tenantId, (string) $data['location_code'], true);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $unitCodes = $data['serialized_item_codes'] ?? [];
                if ($item->tracking_mode === 'SERIALIZED' && count($unitCodes) !== (int) $quantity) {
                    throw ValidationException::withMessages(['serialized_item_codes' => ['Returned units must match the return quantity.']]);
                }
                $data['_idempotency_record_id'] = $recordId;
                $data['reason'] = 'Customer sale return';
                $data['source_type'] = 'SaleReturnLine';
                foreach ($unitCodes as $unitCode) {
                    // Bind the selected identity to this item and the current TenantContext.
                    $unit = $this->repository->findSerializedItemsByCodesForUpdate($item->id, [$unitCode])->first();
                    if ($unit === null || $this->repository->latestSerializedItemMovement($tenantId, $unit->id)?->to_location_id !== null) {
                        throw ValidationException::withMessages(['serialized_item_codes' => ['A returned unit is not currently issued.']]);
                    }
                    $this->appendMovement($tenantId, $item, null, $location->id, 1, 'RECEIVE', $data, $unit->id);
                }
                if ($item->tracking_mode !== 'SERIALIZED') {
                    $this->appendMovement($tenantId, $item, null, $location->id, $quantity, 'RECEIVE', $data);
                }
                $movement = $this->repository->movementBySource($tenantId, 'SaleReturnLine', (string) $data['source_code']);
                return ['movement_id' => $movement?->id, 'item' => $this->itemResource($tenantId, $item)];
            });
        }, 201);
    }
    public function reserveForSale(array $data, ?string $idempotencyKey): array
    {
        // Reserve physical custody for a confirmed sale without changing on-hand movements.
        return $this->runIdempotent('inventory.reserve.sale', $idempotencyKey, $data, function (int $tenantId) use ($data): array {
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $location = $this->activeLocationByCode($tenantId, (string) $data['location_code'], true);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $unitCodes = $data['serialized_item_codes'] ?? [];
                $this->assertAvailable($tenantId, $item, (int) $location->id, $quantity, $unitCodes);
                $reservation = $this->repository->createReservation($tenantId, [
                    'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_reservations', CarbonImmutable::now()),
                    'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id,
                    'source_type' => 'SaleOrderLine', 'source_code' => (string) $data['source_code'],
                    'quantity' => $quantity, 'remaining_quantity' => $quantity,
                    'serialized_item_codes' => $unitCodes, 'status' => 'ACTIVE',
                ]);
                return ['code' => $reservation->code, 'quantity' => $reservation->quantity, 'remaining_quantity' => $reservation->remaining_quantity];
            });
        }, 201);
    }

    public function releaseSaleReservation(string $reservationCode, ?float $quantity = null): void
    {
        // Release custody reservations without appending a physical stock movement.
        $tenantId = $this->resolveCurrentTenantId();
        DB::transaction(function () use ($tenantId, $reservationCode, $quantity): void {
            // Resolve and lock the reservation within the current TenantContext.
            $reservation = $this->repository->reservationByCode($reservationCode, true);
            if ($reservation === null || $reservation->status !== 'ACTIVE') {
                return;
            }
            $releaseQuantity = $quantity ?? (float) $reservation->remaining_quantity;
            if ($releaseQuantity <= 0 || $releaseQuantity > (float) $reservation->remaining_quantity + 0.001) {
                throw ValidationException::withMessages(['quantity' => ['The release exceeds the remaining reserved quantity.']]);
            }
            $remaining = max(0, (float) $reservation->remaining_quantity - $releaseQuantity);
            $this->repository->updateReservation($reservation, [
                'remaining_quantity' => $remaining,
                'status' => $remaining <= 0.001 ? 'RELEASED' : 'ACTIVE',
            ]);
        });
    }

    public function moveStock(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.move', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $locationCodes = [
                    (string) $data['from_location_code'],
                    (string) $data['to_location_code'],
                ];
                sort($locationCodes, SORT_STRING);
                $lockedLocations = [];
                foreach (array_unique($locationCodes) as $locationCode) {
                    $lockedLocations[$locationCode] = $this->activeLocationByCode(
                        $tenantId,
                        $locationCode,
                        true,
                        $locationCode === (string) $data['to_location_code'],
                    );
                }
                $from = $lockedLocations[(string) $data['from_location_code']];
                $to = $lockedLocations[(string) $data['to_location_code']];
                if ($from->id === $to->id) {
                    throw ValidationException::withMessages(['to_location_code' => ['Choose a different destination.']]);
                }
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $this->assertAvailable($tenantId, $item, $from->id, $quantity, $data['serialized_item_codes'] ?? []);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedSerializedItemsByCodes($tenantId, $item, $data['serialized_item_codes']) as $unit) {
                        $this->appendMovement($tenantId, $item, $from->id, $to->id, 1, 'MOVE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, $from->id, $to->id, $quantity, 'MOVE', $data);
                }

                return $this->itemResource($tenantId, $item);
            });
        });
    }

    public function issueStock(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.issue', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $location = $this->activeLocationByCode($tenantId, (string) $data['location_code'], true, false);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $reservation = isset($data['reservation_code'])
                    ? $this->repository->reservationByCode((string) $data['reservation_code'], true)
                    : null;
                if ($reservation !== null && ($reservation->inventory_item_id !== $item->id
                    || $reservation->inventory_location_id !== $location->id
                    || $reservation->status !== 'ACTIVE'
                    || (float) $reservation->remaining_quantity + 0.001 < $quantity)) {
                    throw ValidationException::withMessages(['reservation_code' => ['The inventory reservation is unavailable.']]);
                }
                $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['serialized_item_codes'] ?? [], $reservation?->code);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedSerializedItemsByCodes($tenantId, $item, $data['serialized_item_codes'] ?? []) as $unit) {
                        $this->appendMovement($tenantId, $item, $location->id, null, 1, 'ISSUE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, $location->id, null, $quantity, 'ISSUE', $data);
                }
                if ($reservation !== null) {
                    $remaining = max(0, (float) $reservation->remaining_quantity - $quantity);
                    $this->repository->updateReservation($reservation, [
                        'remaining_quantity' => $remaining,
                        'status' => $remaining <= 0.001 ? 'CONSUMED' : 'ACTIVE',
                    ]);
                }

                return $this->itemResource($tenantId, $item);
            });
        });
    }

    public function adjustStock(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.adjust', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $location = $this->activeLocationByCode(
                    $tenantId,
                    (string) $data['location_code'],
                    true,
                    $data['direction'] === 'IN',
                );
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $type = $data['direction'] === 'IN' ? 'ADJUST_IN' : 'ADJUST_OUT';
                if ($type === 'ADJUST_OUT') {
                    $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['serialized_item_codes'] ?? []);
                }
                if ($type === 'ADJUST_IN' && $item->tracking_mode === 'UNIQUE'
                    && array_sum($this->repository->balances($tenantId, $item->id)) > 0) {
                    throw ValidationException::withMessages([
                        'inventory_item_code' => ['A unique item can only have one unit in custody.'],
                    ]);
                }

                if ($item->tracking_mode === 'SERIALIZED') {
                    $identifiers = $data['serialized_item_identifiers'] ?? [];
                    if ($type === 'ADJUST_IN') {
                        $identifiers = $this->validateSerializedReceive($tenantId, $item, $quantity, $identifiers);
                        foreach ($identifiers as $identifier) {
                            // Use the same pre-insert guard as normal receipt.
                            $unit = $this->createSerializedItemForReceipt($tenantId, $item, $identifier);
                            $this->appendMovement($tenantId, $item, null, $location->id, 1, $type, $data, $unit->id);
                        }
                    } else {
                    foreach ($this->lockedSerializedItemsByCodes($tenantId, $item, $data['serialized_item_codes'] ?? []) as $unit) {
                            $this->appendMovement($tenantId, $item, $location->id, null, 1, $type, $data, $unit->id);
                        }
                    }
                } else {
                    $this->appendMovement($tenantId, $item, $type === 'ADJUST_OUT' ? $location->id : null,
                        $type === 'ADJUST_IN' ? $location->id : null, $quantity, $type, $data);
                }

                return $this->itemResource($tenantId, $item);
            });
        });
    }

    public function ensureMainShopForTenant(int $tenantId): void
    {
        $this->repository->ensureMainShop($tenantId,
            $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_locations', CarbonImmutable::now()));
    }
    public function receivePawnCollateral(PawnCollateralItem $collateral): InventoryItem
    {
        // Create an individual custody stock item and receipt movement for one collateral entry.
        $tenantId = (int) $collateral->tenant_id;
        $this->ensureMainShopForTenant($tenantId);
        $location = $this->repository->defaultLocation($tenantId);
        if ($location === null) {
            throw ValidationException::withMessages(['inventory_location' => ['The default Inventory location is unavailable.']]);
        }
        $quantity = max(1, (int) $collateral->quantity);

        // Retain pawn-specific identity and details without classifying customer property as owned stock.
        $item = $this->createInventoryItem($tenantId, [
            'name' => $collateral->name,
            'description' => mb_substr(trim(implode(' | ', array_filter([
                'Pawn collateral '.$collateral->code,
                $collateral->brand_name,
                $collateral->description,
            ]))), 0, 255),
            'tracking_mode' => 'QUANTITY',
            'unit_code' => 'unit',
            'quantity' => $quantity,
        ]);

        // Record custody with an auditable cross-module source reference.
        $this->appendMovement($tenantId, $item, null, (int) $location->id, $quantity, 'RECEIVE', [
            'reason' => 'Pawn collateral received into custody',
            'source_type' => 'PawnCollateralItem',
            'source_code' => $collateral->code,
        ]);

        return $item;
    }

    /**
     * Validate a typed command against its resolved tenant-owned item.
     * Returns exact integer thousandths for future mutation orchestration.
     */
    public function validateStockCommandQuantity(InventoryStockCommand $request, InventoryItem $item): int
    {
        // Reject a model from another tenant before trusting tracking or identity attributes.
        if ((int) $item->tenant_id !== $this->resolveCurrentTenantId()) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }

        // Enforce tracking precision and unit-selection shape using exact quantities.
        $quantity = InventoryQuantity::parse($request->quantity, $item->tracking_mode);
        if (property_exists($request, 'inventoryItemCode')
            && $request->inventoryItemCode !== null && $request->inventoryItemCode !== $item->code) {
            throw new InvalidTenantRequest(__('inventory.item_unavailable'));
        }
        if (property_exists($request, 'serializedItemCodes')) {
            $this->validateSerializedItemSelection($item, $quantity, $request->serializedItemCodes);
        }
        // Validate optional receipt references against the already resolved item as well.
        if ($request instanceof InventoryReceiveRequest) {
            $this->validateExistingReceiptReferences($request, $item);
            $this->validateSerializedReceive($this->resolveCurrentTenantId(), $item, $request->quantity, $request->serializedItemIdentifiers);
        }

        return $quantity;
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
        ?string $key,
        array $data,
        callable $callback,
        int $responseCode = 200,
    ): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $data);
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

    private function createInventoryItem(int $tenantId, array $data): InventoryItem
    {
        $catalogItem = isset($data['catalog_item_code'])
            ? $this->catalogItemService->availableForInventoryByCode((string) $data['catalog_item_code'])
            : null;
        if (isset($data['catalog_item_code']) && $catalogItem === null) {
            throw new InvalidTenantRequest(__('inventory.catalog_unavailable'));
        }

        if ($catalogItem !== null
            && isset($data['tracking_mode'])
            && strtoupper((string) $data['tracking_mode']) !== $catalogItem['tracking_mode']) {
            throw new InvalidTenantRequest(__('inventory.tracking_mismatch'));
        }
        if ($catalogItem !== null
            && isset($data['unit_code'])
            && (string) $data['unit_code'] !== (string) $catalogItem['unit_code']) {
            throw new InvalidTenantRequest(__('inventory.unit_mismatch'));
        }

        $unitCode = (string) ($data['unit_code'] ?? $catalogItem['unit_code'] ?? 'unit');
        $unitId = $this->catalogTaxonomyService->unitIdForCodeInTenant($tenantId, $unitCode);
        if ($unitId === null) {
            throw new InvalidTenantRequest(__('inventory.unit_unavailable'));
        }

        $mode = strtoupper((string) ($data['tracking_mode'] ?? $catalogItem['tracking_mode'] ?? 'QUANTITY'));
        if (! in_array($mode, ['UNIQUE', 'SERIALIZED', 'QUANTITY'], true)) {
            throw new InvalidTenantRequest(__('inventory.tracking_invalid'));
        }
        // Freeform serialized items need a valid measurement unit, but no Catalog record.
        InventoryQuantity::parse($data['quantity'], $mode);
        $name = trim((string) ($data['name'] ?? $catalogItem['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidTenantRequest(__('inventory.name_required'));
        }

        return $this->repository->createItem($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_items', CarbonImmutable::now()),
            'catalog_item_id' => $catalogItem['id'] ?? null,
            'unit_id' => $unitId,
            'tracking_mode' => $mode,
            'name' => $name,
            'description' => $data['description'] ?? $catalogItem['description'] ?? null,
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
        float $quantity,
        string $type,
        array $data,
        ?int $unitId = null,
    ): void
    {
        // The repository assigns TenantContext, recording time, and the checkpoint anchor lock.
        $movement = $this->repository->createMovement([
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_movements', CarbonImmutable::now()),
            'inventory_item_id' => $item->id,
            'inventory_serialized_item_id' => $unitId,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'quantity' => $quantity,
            'movement_type' => $type,
            'reason' => $data['reason'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_code' => $data['source_code'] ?? null,
            'idempotency_record_id' => $data['_idempotency_record_id'] ?? null,
            'created_by' => Auth::id(),
            'occurred_at' => now(),
        ]);
        // The repository returns null without inserting if the item anchor no longer exists.
        if ($movement === null) {
            throw new TenantNotFound(__('inventory.item_not_found'));
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
        $serializedItems = $this->repository->serializedItems($tenantId, $item->id)->map(function ($unit) use ($tenantId): array {
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
            'name' => $location->name,
            'type' => $location->type,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
            'is_sellable' => $location->is_sellable,
        ];
    }
}
