<?php

namespace App\Repository;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\InventoryModule\InventoryMovement;
use App\Models\InventoryModule\InventoryUnit;
use App\Models\InventoryModule\InventoryReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryRepository
{
    public function locations(int $tenantId): Collection
    {
        return InventoryLocation::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    public function findLocationByCode(int $tenantId, string $code, bool $lock = false): ?InventoryLocation
    {
        $query = InventoryLocation::query()->where('tenant_id', $tenantId)->where('code', $code);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function createLocation(int $tenantId, array $data): InventoryLocation
    {
        return InventoryLocation::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function locationNameExists(int $tenantId, string $name, ?int $exceptId = null): bool
    {
        return InventoryLocation::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function updateLocation(InventoryLocation $location, array $data): InventoryLocation
    {
        $location->update($data);
        return $location->refresh();
    }

    public function ensureMainShop(int $tenantId, string $code): InventoryLocation
    {
        return InventoryLocation::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Main Shop'],
            ['code' => $code, 'type' => 'SHOP', 'is_default' => true, 'is_active' => true],
        );
    }

    public function defaultLocation(int $tenantId): ?InventoryLocation
    {
        return InventoryLocation::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function items(int $tenantId, ?string $search): Collection
    {
        return InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->when($search, fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->with(['catalogItem:id,business_code,name', 'unit:id,code,name'])
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    public function findItemByCode(int $tenantId, string $code, bool $lock = false): ?InventoryItem
    {
        $query = InventoryItem::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    public function createItem(int $tenantId, array $data): InventoryItem
    {
        return InventoryItem::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function createUnit(int $tenantId, int $itemId, ?string $identifier, string $code): InventoryUnit
    {
        return InventoryUnit::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'inventory_item_id' => $itemId,
            'identifier' => $identifier,
        ]);
    }

    public function unitIdentifierExists(int $tenantId, string $identifier): bool
    {
        return InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier', $identifier)
            ->exists();
    }

    public function findUnitsByCodesForUpdate(int $tenantId, int $itemId, array $unitCodes): Collection
    {
        return InventoryUnit::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->whereIn('code', $unitCodes)->orderBy('id')->lockForUpdate()->get();
    }

    public function createMovement(int $tenantId, array $data): InventoryMovement
    {
        return InventoryMovement::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function movements(int $tenantId, int $itemId): Collection
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->with(['fromLocation:id,code,name', 'toLocation:id,code,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    public function balance(int $tenantId, int $itemId, int $locationId): string
    {
        $received = DB::table('inventory_movements')->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)->where('to_location_id', $locationId)->sum('quantity');
        $issued = DB::table('inventory_movements')->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)->where('from_location_id', $locationId)->sum('quantity');

        return number_format((float) $received - (float) $issued, 3, '.', '');
    }

    public function balances(int $tenantId, int $itemId): array
    {
        $rows = DB::table('inventory_movements')->select('to_location_id', DB::raw('SUM(quantity) as quantity'))
            ->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->whereNotNull('to_location_id')->groupBy('to_location_id')->get();
        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->to_location_id] = (float) $row->quantity;
        }
        $outRows = DB::table('inventory_movements')->select('from_location_id', DB::raw('SUM(quantity) as quantity'))
            ->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->whereNotNull('from_location_id')->groupBy('from_location_id')->get();
        foreach ($outRows as $row) {
            $locationId = (int) $row->from_location_id;
            $balances[$locationId] = ($balances[$locationId] ?? 0) - (float) $row->quantity;
        }

        return $balances;
    }

    public function reservedBalance(int $tenantId, int $itemId, int $locationId): float
    {
        return (float) InventoryReservation::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)->where('status', 'ACTIVE')->sum('remaining_quantity');
    }

    public function activeReservedUnitCodes(int $tenantId, int $itemId, int $locationId): array
    {
        return InventoryReservation::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)->where('status', 'ACTIVE')->get(['inventory_unit_codes'])
            ->flatMap(fn (InventoryReservation $row) => $row->inventory_unit_codes ?? [])->all();
    }

    public function createReservation(int $tenantId, array $data): InventoryReservation
    {
        return InventoryReservation::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function reservationByCode(int $tenantId, string $code, bool $lock = false): ?InventoryReservation
    {
        $query = InventoryReservation::query()->where('tenant_id', $tenantId)->where('code', $code);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function updateReservation(InventoryReservation $reservation, array $data): InventoryReservation
    {
        $reservation->update($data);
        return $reservation->refresh();
    }

    public function units(int $tenantId, int $itemId): Collection
    {
        return InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->orderBy('id')
            ->get();
    }

    public function latestUnitMovement(int $tenantId, int $unitId): ?InventoryMovement
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_unit_id', $unitId)
            ->with(['fromLocation:id,code,name', 'toLocation:id,code,name'])
            ->latest('id')
            ->first();
    }
}
