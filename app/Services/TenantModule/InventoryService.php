<?php

namespace App\Services\TenantModule;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\PawnModule\PawnCollateralItem;
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

    public function units(): array
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
        $data['name'] = trim($data['name']);
        if ($this->repository->locationNameExists($tenantId, $data['name'])) {
            throw ValidationException::withMessages(['name' => ['This location name is already in use.']]);
        }
        $data['code'] = $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_locations', CarbonImmutable::now());
        $location = $this->repository->createLocation($tenantId, $data);
        return $this->locationResource($location);
    }

    public function updateLocationByCode(string $locationCode, array $data): array
    {
        // Resolve and lock the location by its public tenant-scoped business code.
        $tenantId = $this->resolveCurrentTenantId();
        return DB::transaction(function () use ($tenantId, $locationCode, $data): array {
            $location = $this->repository->findLocationByCode($tenantId, $locationCode, true);
            abort_if($location === null, 404);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if ($this->repository->locationNameExists($tenantId, $data['name'], $location->id)) {
                    throw ValidationException::withMessages(['name' => ['This location name is already in use.']]);
                }
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
        $item = $this->repository->findItemByCode($tenantId, $itemCode);
        abort_if($item === null, 404);
        return $this->itemResource($tenantId, $item);
    }

    public function findForOwnershipByCode(string $itemCode): InventoryItem
    {
        // Resolve a public business code before Ownership uses the internal model ID.
        $item = $this->repository->findItemByCode($this->resolveCurrentTenantId(), $itemCode, true);
        abort_if($item === null, 404);
        return $item;
    }

    public function movementsByCode(string $itemCode): array
    {
        // Resolve the public Inventory item code before reading movement history.
        $tenantId = $this->resolveCurrentTenantId();
        $item = $this->repository->findItemByCode($tenantId, $itemCode);
        abort_if($item === null, 404);
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
                $unitIdentifiers = $this->validateSerializedReceive(
                    $tenantId,
                    $item,
                    $quantity,
                    $data['unit_identifiers'] ?? [],
                );

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($unitIdentifiers as $identifier) {
                        $unit = $this->repository->createUnit($tenantId, $item->id, $identifier,
                            $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_units', CarbonImmutable::now()));
                        $this->appendMovement($tenantId, $item, null, $location->id, 1, 'RECEIVE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, null, $location->id, $quantity, 'RECEIVE', $data);
                }

                return $this->itemResource($tenantId, $item->refresh());
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
                $unitCodes = $data['inventory_unit_codes'] ?? [];
                $this->assertAvailable($tenantId, $item, (int) $location->id, $quantity, $unitCodes);
                $reservation = $this->repository->createReservation($tenantId, [
                    'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_reservations', CarbonImmutable::now()),
                    'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id,
                    'source_type' => 'SaleOrderLine', 'source_code' => (string) $data['source_code'],
                    'quantity' => $quantity, 'remaining_quantity' => $quantity,
                    'inventory_unit_codes' => $unitCodes, 'status' => 'ACTIVE',
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
            $reservation = $this->repository->reservationByCode($tenantId, $reservationCode, true);
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

    public function move(array $data, ?string $idempotencyKey): array
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
                $this->assertAvailable($tenantId, $item, $from->id, $quantity, $data['inventory_unit_codes'] ?? []);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedUnitsByCodes($tenantId, $item, $data['inventory_unit_codes']) as $unit) {
                        $this->appendMovement($tenantId, $item, $from->id, $to->id, 1, 'MOVE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, $from->id, $to->id, $quantity, 'MOVE', $data);
                }

                return $this->itemResource($tenantId, $item);
            });
        });
    }

    public function issue(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.issue', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItemByCode($tenantId, (string) $data['inventory_item_code']);
                $location = $this->activeLocationByCode($tenantId, (string) $data['location_code'], true, false);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $reservation = isset($data['reservation_code'])
                    ? $this->repository->reservationByCode($tenantId, (string) $data['reservation_code'], true)
                    : null;
                if ($reservation !== null && ($reservation->inventory_item_id !== $item->id
                    || $reservation->inventory_location_id !== $location->id
                    || $reservation->status !== 'ACTIVE'
                    || (float) $reservation->remaining_quantity + 0.001 < $quantity)) {
                    throw ValidationException::withMessages(['reservation_code' => ['The inventory reservation is unavailable.']]);
                }
                $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['inventory_unit_codes'] ?? [], $reservation?->code);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedUnitsByCodes($tenantId, $item, $data['inventory_unit_codes'] ?? []) as $unit) {
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

    public function adjust(array $data, ?string $idempotencyKey): array
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
                    $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['inventory_unit_codes'] ?? []);
                }
                if ($type === 'ADJUST_IN' && $item->tracking_mode === 'UNIQUE'
                    && array_sum($this->repository->balances($tenantId, $item->id)) > 0) {
                    throw ValidationException::withMessages([
                        'inventory_item_code' => ['A unique item can only have one unit in custody.'],
                    ]);
                }

                if ($item->tracking_mode === 'SERIALIZED') {
                    $identifiers = $data['unit_identifiers'] ?? [];
                    if ($type === 'ADJUST_IN') {
                        $identifiers = $this->validateSerializedReceive($tenantId, $item, $quantity, $identifiers);
                        foreach ($identifiers as $identifier) {
                            $unit = $this->repository->createUnit($tenantId, $item->id, $identifier,
                                $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_units', CarbonImmutable::now()));
                            $this->appendMovement($tenantId, $item, null, $location->id, 1, $type, $data, $unit->id);
                        }
                    } else {
                    foreach ($this->lockedUnitsByCodes($tenantId, $item, $data['inventory_unit_codes'] ?? []) as $unit) {
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

    private function createInventoryItem(int $tenantId, array $data): InventoryItem
    {
        $catalogItem = isset($data['catalog_item_code'])
            ? $this->catalogItemService->availableForInventoryByCode((string) $data['catalog_item_code'])
            : null;
        if (isset($data['catalog_item_code']) && $catalogItem === null) {
            throw ValidationException::withMessages(['catalog_item_code' => ['The selected catalog item is unavailable.']]);
        }

        if ($catalogItem !== null
            && isset($data['tracking_mode'])
            && strtoupper((string) $data['tracking_mode']) !== $catalogItem['tracking_mode']) {
            throw ValidationException::withMessages([
                'tracking_mode' => ['Tracking mode must match the selected catalog item.'],
            ]);
        }
        if ($catalogItem !== null
            && isset($data['unit_code'])
            && (string) $data['unit_code'] !== (string) $catalogItem['unit_code']) {
            throw ValidationException::withMessages([
                'unit_code' => ['Unit must match the selected catalog item.'],
            ]);
        }

        $unitCode = (string) ($data['unit_code'] ?? $catalogItem['unit_code'] ?? 'unit');
        $unitId = $this->catalogTaxonomyService->unitIdForCodeInTenant($tenantId, $unitCode);
        if ($unitId === null) {
            throw ValidationException::withMessages(['unit_code' => ['The selected unit is unavailable.']]);
        }

        $mode = strtoupper((string) ($data['tracking_mode'] ?? $catalogItem['tracking_mode'] ?? 'QUANTITY'));
        if (! in_array($mode, ['UNIQUE', 'SERIALIZED', 'QUANTITY'], true)) {
            throw ValidationException::withMessages(['tracking_mode' => ['Choose a supported tracking mode.']]);
        }
        if ($mode === 'UNIQUE' && (float) $data['quantity'] !== 1.0) {
            throw ValidationException::withMessages(['quantity' => ['Unique inventory must be received one item at a time.']]);
        }
        if ($mode === 'SERIALIZED' && $catalogItem === null) {
            throw ValidationException::withMessages(['catalog_item_code' => ['Serialized inventory requires a catalog item.']]);
        }

        return $this->repository->createItem($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_items', CarbonImmutable::now()),
            'catalog_item_id' => $catalogItem['id'] ?? null,
            'unit_id' => $unitId,
            'tracking_mode' => $mode,
            'name' => trim((string) ($data['name'] ?? $catalogItem['name'] ?? '')),
            'description' => $data['description'] ?? $catalogItem['description'] ?? null,
            'quantity_scale' => 1,
        ]);
    }

    private function validateQuantityForMode(InventoryItem $item, mixed $rawQuantity): float
    {
        $quantity = (float) $rawQuantity;
        if ($quantity <= 0 || ($item->tracking_mode !== 'QUANTITY' && floor($quantity) !== $quantity)) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be positive and must be a whole number for tracked units.']]);
        }
        if ($item->tracking_mode === 'UNIQUE' && $quantity !== 1.0) {
            throw ValidationException::withMessages(['quantity' => ['Unique inventory quantity must be one.']]);
        }
        return $quantity;
    }

    private function validateSerializedReceive(
        int $tenantId,
        InventoryItem $item,
        float $quantity,
        array $identifiers,
    ): array
    {
        if ($item->tracking_mode !== 'SERIALIZED') {
            if ($identifiers !== []) {
                throw ValidationException::withMessages(['unit_identifiers' => ['Unit identifiers are only valid for serialized inventory.']]);
            }
            return [];
        }
        if (count($identifiers) !== (int) $quantity) {
            throw ValidationException::withMessages(['unit_identifiers' => ['Provide one identifier entry per serialized unit.']]);
        }
        $normalizedIdentifiers = array_map(
            static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value),
            $identifiers,
        );
        $providedIdentifiers = array_values(array_filter($normalizedIdentifiers));
        if (count(array_unique($providedIdentifiers)) !== count($providedIdentifiers)) {
            throw ValidationException::withMessages([
                'unit_identifiers' => ['Serialized unit identifiers must be unique.'],
            ]);
        }
        foreach ($providedIdentifiers as $identifier) {
            if ($this->repository->unitIdentifierExists($tenantId, $identifier)) {
                throw ValidationException::withMessages([
                    'unit_identifiers' => ['A serialized unit identifier is already in use.'],
                ]);
            }
        }
        return $normalizedIdentifiers;
    }

    private function assertAvailable(int $tenantId, InventoryItem $item, int $locationId, float $quantity, array $unitIds, ?string $reservationCode = null): void
    {
        if ($item->tracking_mode === 'SERIALIZED') {
            if (count($unitIds) !== (int) $quantity || count(array_unique($unitIds)) !== count($unitIds)) {
                throw ValidationException::withMessages(['inventory_unit_codes' => ['Select one distinct serialized unit per quantity.']]);
            }
            $units = $this->lockedUnitsByCodes($tenantId, $item, $unitIds);
            if ($units->count() !== count($unitIds)) {
                throw ValidationException::withMessages(['inventory_unit_codes' => ['One or more serialized units are unavailable.']]);
            }
            $reservedCodes = $this->repository->activeReservedUnitCodes($tenantId, $item->id, $locationId);
            $ownReservationCodes = $reservationCode === null ? [] : ($this->repository->reservationByCode($tenantId, $reservationCode)?->inventory_unit_codes ?? []);
            if (array_diff($unitIds, $ownReservationCodes) !== [] && $reservationCode !== null) {
                throw ValidationException::withMessages(['inventory_unit_codes' => ['The selected units do not belong to this reservation.']]);
            }
            foreach ($units as $unit) {
                $last = \App\Models\InventoryModule\InventoryMovement::query()
                    ->where('tenant_id', $tenantId)->where('inventory_unit_id', $unit->id)->latest('id')->first();
                if ((int) ($last?->to_location_id ?? 0) !== $locationId) {
                    throw ValidationException::withMessages(['inventory_unit_codes' => ['A selected unit is not at this location.']]);
                }
                if (in_array($unit->code, $reservedCodes, true) && ! in_array($unit->code, $ownReservationCodes, true)) {
                    throw ValidationException::withMessages(['inventory_unit_codes' => ['A selected unit is reserved for another sale.']]);
                }
            }
            return;
        }
        $available = (float) $this->repository->balance($tenantId, $item->id, $locationId)
            - $this->repository->reservedBalance($tenantId, $item->id, $locationId);
        if ($reservationCode !== null) {
            $reservation = $this->repository->reservationByCode($tenantId, $reservationCode);
            $available += (float) ($reservation?->remaining_quantity ?? 0);
        }
        if ($available + 0.001 < $quantity) {
            throw ValidationException::withMessages(['quantity' => ['There is not enough available inventory at this location.']]);
        }
    }

    private function lockedUnitsByCodes(int $tenantId, InventoryItem $item, array $unitCodes)
    {
        // Resolve serialized units by business code while keeping database IDs internal to the movement ledger.
        return $this->repository->findUnitsByCodesForUpdate($tenantId, $item->id, $unitCodes);
    }

    private function activeLocationByCode(int $tenantId, string $locationCode, bool $lock, bool $requireActive = true): InventoryLocation
    {
        // Resolve a tenant-owned location using its business code.
        $location = $this->repository->findLocationByCode($tenantId, $locationCode, $lock);
        if ($location === null || ($requireActive && ! $location->is_active)) {
            throw ValidationException::withMessages(['location_code' => ['The selected location is unavailable.']]);
        }
        return $location;
    }

    private function lockedItemByCode(int $tenantId, string $itemCode): InventoryItem
    {
        // Resolve and lock the tenant-owned Inventory item using its public business code.
        $item = $this->repository->findItemByCode($tenantId, $itemCode, true);
        if ($item === null) {
            abort(404);
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
        $this->repository->createMovement($tenantId, [
            'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'inventory_movements', CarbonImmutable::now()),
            'inventory_item_id' => $item->id,
            'inventory_unit_id' => $unitId,
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
                ];
            }
        }
        $units = $this->repository->units($tenantId, $item->id)->map(function ($unit) use ($tenantId): array {
            $lastMovement = $this->repository->latestUnitMovement($tenantId, $unit->id);
            return [
                'code' => $unit->code,
                'identifier' => $unit->identifier,
                'location_code' => $lastMovement?->toLocation?->code,
                'location' => $lastMovement?->toLocation?->name,
                'is_reserved' => in_array($unit->code, $this->repository->activeReservedUnitCodes($tenantId, $item->id, (int) ($lastMovement?->to_location_id ?? 0)), true),
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
            'available_quantity' => number_format(array_sum($balances) - collect($balances)->keys()->sum(fn ($locationId) => $this->repository->reservedBalance($tenantId, $item->id, (int) $locationId)), 3, '.', ''),
            'locations' => $locationRows,
            'units' => $units,
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
        ];
    }
}
