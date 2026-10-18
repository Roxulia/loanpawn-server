<?php

namespace App\Repository;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\InventoryModule\InventoryMovement;
use App\Models\InventoryModule\InventoryUnit;
use App\Models\InventoryModule\InventoryReservation;
use App\Exceptions\TenantNotFound;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryRepository
{
    public function locations(int $tenantId): Collection
    {
        return InventoryLocation::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Resolve a location by public business code within the current TenantContext.
     * The model's BelongToTenant global scope applies the tenant restriction.
     * Exclusive locking supports location edits; shared locking supports stock
     * operations that must prevent concurrent location restriction changes.
     * Exclusive locking takes precedence when both flags are enabled.
     * Locks require a caller-owned transaction that stays open until work finishes.
     * Return the complete model, including update_key, or null when not found.
     */
    public function findLocationByCode(
        string $code,
        bool $lock = false,
        bool $sharedLock = false,
    ): ?InventoryLocation
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Let BelongToTenant scope this code lookup using the current TenantContext.
        $query = InventoryLocation::query()
            ->where('code', $code);

        // Preserve exclusive locking for existing callers and add an optional shared lock.
        if ($lock) {
            $query->lockForUpdate();
        } elseif ($sharedLock) {
            $query->sharedLock();
        }

        // Keep missing-record handling and location eligibility decisions in the service.
        return $query->first();
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

    /**
     * Resolve an item by public business code within the current TenantContext.
     * The model's BelongToTenant global scope applies the tenant restriction.
     * The full model includes update_key for later service-level version checks.
     * With locking enabled, the caller must own an active transaction and keep it
     * open until its stock operation finishes; this method does not start one.
     * Missing items return null so the service can choose the domain response.
     */
    public function findItemByCode(string $code, bool $lock = false): ?InventoryItem
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Let BelongToTenant scope this code lookup using the current TenantContext.
        $query = InventoryItem::query()
            ->where('code', $code);

        // Acquire the item anchor's exclusive lock within the caller's transaction.
        if ($lock) {
            $query->lockForUpdate();
        }

        // Return the complete item, including its version, without loading related collections.
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

    /**
     * Resolve a reservation by public code within the current TenantContext.
     * An expected item ID optionally binds the lookup to a caller's resolved item.
     * Code-only lookup supports release workflows that first resolve the reservation.
     * Exclusive locking requires a caller-owned transaction; missing or mismatched
     * records return null for the service to handle without applying status filters.
     */
    public function reservationByCode(
        string $code,
        bool $lock = false,
        ?int $inventoryItemId = null,
    ): ?InventoryReservation
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Resolve the tenant-scoped code and optionally constrain its item binding.
        $query = InventoryReservation::query()
            ->where('code', $code)
            ->when($inventoryItemId !== null, fn ($query) => $query->where('inventory_item_id', $inventoryItemId));

        // Keep the reservation lock in the caller's transaction until its work completes.
        if ($lock) {
            $query->lockForUpdate();
        }

        // Leave status, location, source, and remaining-quantity validation to the service.
        return $query->first();
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

    public function movementBySource(int $tenantId, string $sourceType, string $sourceCode): ?InventoryMovement
    {
        return InventoryMovement::query()->where('tenant_id', $tenantId)->where('source_type', $sourceType)
            ->where('source_code', $sourceCode)->latest('id')->first();
    }
}
