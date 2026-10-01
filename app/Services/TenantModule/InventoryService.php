<?php

namespace App\Services\TenantModule;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Repository\InventoryRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantIdempotencyService;
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
        return $this->catalogTaxonomyService->units();
    }

    public function createLocation(array $data): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $data['name'] = trim($data['name']);
        if ($this->repository->locationNameExists($tenantId, $data['name'])) {
            throw ValidationException::withMessages(['name' => ['This location name is already in use.']]);
        }
        $location = $this->repository->createLocation($tenantId, $data);
        return $this->locationResource($location);
    }

    public function updateLocation(int $id, array $data): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        return DB::transaction(function () use ($tenantId, $id, $data): array {
            $location = $this->repository->findLocation($tenantId, $id, true);
            abort_if($location === null, 404);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                if ($this->repository->locationNameExists($tenantId, $data['name'], $location->id)) {
                    throw ValidationException::withMessages(['name' => ['This location name is already in use.']]);
                }
            }
            if ($location->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => ['The default Main Shop location cannot be deactivated.'],
                ]);
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

    public function detail(int $itemId): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $item = $this->repository->findItem($tenantId, $itemId);
        abort_if($item === null, 404);

        return $this->itemResource($tenantId, $item);
    }

    public function movements(int $itemId): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        abort_if($this->repository->findItem($tenantId, $itemId) === null, 404);
        return $this->repository->movements($tenantId, $itemId)->map(
            fn ($movement): array => [
                'id' => $movement->id,
                'type' => $movement->movement_type,
                'quantity' => $movement->quantity,
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
                $location = $this->activeLocation($tenantId, (int) $data['location_id'], true);
                $item = isset($data['inventory_item_id'])
                    ? $this->lockedItem($tenantId, (int) $data['inventory_item_id'])
                    : $this->createInventoryItem($tenantId, $data);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                if ($item->tracking_mode === 'UNIQUE'
                    && array_sum($this->repository->balances($tenantId, $item->id)) > 0) {
                    throw ValidationException::withMessages([
                        'inventory_item_id' => ['This unique item has already been received.'],
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
                        $unit = $this->repository->createUnit($tenantId, $item->id, $identifier);
                        $this->appendMovement($tenantId, $item, null, $location->id, 1, 'RECEIVE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, null, $location->id, $quantity, 'RECEIVE', $data);
                }

                return $this->itemResource($tenantId, $item->refresh());
            });
        }, 201);
    }

    public function move(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('inventory.move', $idempotencyKey, $data, function (int $tenantId, ?int $recordId) use ($data): array {
            $data['_idempotency_record_id'] = $recordId;
            return DB::transaction(function () use ($tenantId, $data): array {
                $item = $this->lockedItem($tenantId, (int) $data['inventory_item_id']);
                $locationIds = [
                    (int) $data['from_location_id'],
                    (int) $data['to_location_id'],
                ];
                sort($locationIds, SORT_NUMERIC);
                $lockedLocations = [];
                foreach (array_unique($locationIds) as $locationId) {
                    $lockedLocations[$locationId] = $this->activeLocation(
                        $tenantId,
                        $locationId,
                        true,
                        $locationId === (int) $data['to_location_id'],
                    );
                }
                $from = $lockedLocations[(int) $data['from_location_id']];
                $to = $lockedLocations[(int) $data['to_location_id']];
                if ($from->id === $to->id) {
                    throw ValidationException::withMessages(['to_location_id' => ['Choose a different destination.']]);
                }
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $this->assertAvailable($tenantId, $item, $from->id, $quantity, $data['inventory_unit_ids'] ?? []);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedUnits($tenantId, $item, $data['inventory_unit_ids']) as $unit) {
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
                $item = $this->lockedItem($tenantId, (int) $data['inventory_item_id']);
                $location = $this->activeLocation($tenantId, (int) $data['location_id'], true, false);
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['inventory_unit_ids'] ?? []);

                if ($item->tracking_mode === 'SERIALIZED') {
                    foreach ($this->lockedUnits($tenantId, $item, $data['inventory_unit_ids']) as $unit) {
                        $this->appendMovement($tenantId, $item, $location->id, null, 1, 'ISSUE', $data, $unit->id);
                    }
                } else {
                    $this->appendMovement($tenantId, $item, $location->id, null, $quantity, 'ISSUE', $data);
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
                $item = $this->lockedItem($tenantId, (int) $data['inventory_item_id']);
                $location = $this->activeLocation(
                    $tenantId,
                    (int) $data['location_id'],
                    true,
                    $data['direction'] === 'IN',
                );
                $quantity = $this->validateQuantityForMode($item, $data['quantity']);
                $type = $data['direction'] === 'IN' ? 'ADJUST_IN' : 'ADJUST_OUT';
                if ($type === 'ADJUST_OUT') {
                    $this->assertAvailable($tenantId, $item, $location->id, $quantity, $data['inventory_unit_ids'] ?? []);
                }
                if ($type === 'ADJUST_IN' && $item->tracking_mode === 'UNIQUE'
                    && array_sum($this->repository->balances($tenantId, $item->id)) > 0) {
                    throw ValidationException::withMessages([
                        'inventory_item_id' => ['A unique item can only have one unit in custody.'],
                    ]);
                }

                if ($item->tracking_mode === 'SERIALIZED') {
                    $identifiers = $data['unit_identifiers'] ?? [];
                    if ($type === 'ADJUST_IN') {
                        $identifiers = $this->validateSerializedReceive($tenantId, $item, $quantity, $identifiers);
                        foreach ($identifiers as $identifier) {
                            $unit = $this->repository->createUnit($tenantId, $item->id, $identifier);
                            $this->appendMovement($tenantId, $item, null, $location->id, 1, $type, $data, $unit->id);
                        }
                    } else {
                        foreach ($this->lockedUnits($tenantId, $item, $data['inventory_unit_ids']) as $unit) {
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
        $this->repository->ensureMainShop($tenantId);
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
        $catalogItem = isset($data['catalog_item_id'])
            ? $this->catalogItemService->availableForInventory((int) $data['catalog_item_id'])
            : null;
        if (isset($data['catalog_item_id']) && $catalogItem === null) {
            throw ValidationException::withMessages(['catalog_item_id' => ['The selected catalog item is unavailable.']]);
        }

        if ($catalogItem !== null
            && isset($data['tracking_mode'])
            && strtoupper((string) $data['tracking_mode']) !== $catalogItem['tracking_mode']) {
            throw ValidationException::withMessages([
                'tracking_mode' => ['Tracking mode must match the selected catalog item.'],
            ]);
        }
        if ($catalogItem !== null
            && isset($data['unit_id'])
            && (int) $data['unit_id'] !== (int) $catalogItem['unit_id']) {
            throw ValidationException::withMessages([
                'unit_id' => ['Unit must match the selected catalog item.'],
            ]);
        }

        $unitId = (int) ($data['unit_id'] ?? $catalogItem['unit_id'] ?? $this->catalogTaxonomyService->defaultUnitId());
        if (! $this->catalogTaxonomyService->unitIsAvailable($unitId)) {
            throw ValidationException::withMessages(['unit_id' => ['The selected unit is unavailable.']]);
        }

        $mode = strtoupper((string) ($data['tracking_mode'] ?? $catalogItem['tracking_mode'] ?? 'QUANTITY'));
        if (! in_array($mode, ['UNIQUE', 'SERIALIZED', 'QUANTITY'], true)) {
            throw ValidationException::withMessages(['tracking_mode' => ['Choose a supported tracking mode.']]);
        }
        if ($mode === 'UNIQUE' && (float) $data['quantity'] !== 1.0) {
            throw ValidationException::withMessages(['quantity' => ['Unique inventory must be received one item at a time.']]);
        }
        if ($mode === 'SERIALIZED' && $catalogItem === null) {
            throw ValidationException::withMessages(['catalog_item_id' => ['Serialized inventory requires a catalog item.']]);
        }

        return $this->repository->createItem($tenantId, [
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

    private function assertAvailable(int $tenantId, InventoryItem $item, int $locationId, float $quantity, array $unitIds): void
    {
        if ($item->tracking_mode === 'SERIALIZED') {
            if (count($unitIds) !== (int) $quantity || count(array_unique($unitIds)) !== count($unitIds)) {
                throw ValidationException::withMessages(['inventory_unit_ids' => ['Select one distinct serialized unit per quantity.']]);
            }
            $units = $this->lockedUnits($tenantId, $item, $unitIds);
            if ($units->count() !== count($unitIds)) {
                throw ValidationException::withMessages(['inventory_unit_ids' => ['One or more serialized units are unavailable.']]);
            }
            foreach ($units as $unit) {
                $last = \App\Models\InventoryModule\InventoryMovement::query()
                    ->where('tenant_id', $tenantId)->where('inventory_unit_id', $unit->id)->latest('id')->first();
                if ((int) ($last?->to_location_id ?? 0) !== $locationId) {
                    throw ValidationException::withMessages(['inventory_unit_ids' => ['A selected unit is not at this location.']]);
                }
            }
            return;
        }
        if ((float) $this->repository->balance($tenantId, $item->id, $locationId) < $quantity) {
            throw ValidationException::withMessages(['quantity' => ['There is not enough available inventory at this location.']]);
        }
    }

    private function lockedUnits(int $tenantId, InventoryItem $item, array $unitIds)
    {
        return $this->repository->findUnitsForUpdate($tenantId, $item->id, $unitIds);
    }

    private function activeLocation(
        int $tenantId,
        int $locationId,
        bool $lock,
        bool $requireActive = true,
    ): InventoryLocation
    {
        $location = $this->repository->findLocation($tenantId, $locationId, $lock);
        if ($location === null || ($requireActive && ! $location->is_active)) {
            throw ValidationException::withMessages(['location_id' => ['The selected location is unavailable.']]);
        }
        return $location;
    }

    private function lockedItem(int $tenantId, int $itemId): InventoryItem
    {
        $item = $this->repository->findItem($tenantId, $itemId, true);
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
            'inventory_item_id' => $item->id,
            'inventory_unit_id' => $unitId,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'quantity' => $quantity,
            'movement_type' => $type,
            'reason' => $data['reason'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
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
                $locationRows[] = ['location_id' => $locationId, 'location' => $locations->get($locationId)?->name, 'quantity' => number_format($quantity, 3, '.', '')];
            }
        }
        $units = $this->repository->units($tenantId, $item->id)->map(function ($unit) use ($tenantId): array {
            $lastMovement = $this->repository->latestUnitMovement($tenantId, $unit->id);
            return [
                'id' => $unit->id,
                'identifier' => $unit->identifier,
                'location_id' => $lastMovement?->to_location_id,
                'location' => $lastMovement?->toLocation?->name,
            ];
        })->all();
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'catalog_item_id' => $item->catalog_item_id,
            'tracking_mode' => $item->tracking_mode,
            'unit_id' => $item->unit_id,
            'unit' => $item->unit?->name,
            'total_quantity' => number_format(array_sum($balances), 3, '.', ''),
            'locations' => $locationRows,
            'units' => $units,
        ];
    }

    private function locationResource(InventoryLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'type' => $location->type,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
        ];
    }
}
