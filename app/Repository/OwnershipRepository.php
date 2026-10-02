<?php

namespace App\Repository;

use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\AcquisitionLot;
use App\Models\OwnershipModule\OwnedItem;
use App\Models\OwnershipModule\OwnershipMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OwnershipRepository
{
    public function inventoryItem(int $tenantId, int $inventoryItemId, bool $lock = false): ?InventoryItem
    {
        $query = InventoryItem::query()->where('tenant_id', $tenantId)->whereKey($inventoryItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItems(int $tenantId, ?string $search): Collection
    {
        return OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)
            ->when($search, fn ($query) => $query->whereHas('inventoryItem', fn ($items) => $items->where('name', 'like', '%'.$search.'%')))
            ->orderByDesc('id')->get();
    }

    public function ownedItem(int $tenantId, int $ownedItemId, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)->whereKey($ownedItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItemByCode(int $tenantId, string $ownedItemCode, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)->where('code', $ownedItemCode);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItemForInventory(int $tenantId, int $inventoryItemId, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $inventoryItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function createOwnedItem(int $tenantId, array $data): OwnedItem
    {
        return OwnedItem::query()->create(['tenant_id' => $tenantId, ...$data]);
    }

    public function createLot(int $tenantId, array $data): AcquisitionLot
    {
        return AcquisitionLot::query()->create(['tenant_id' => $tenantId, ...$data]);
    }

    public function lotByCode(int $tenantId, int $ownedItemId, string $lotCode, bool $lock = false): ?AcquisitionLot
    {
        $query = AcquisitionLot::query()->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->where('code', $lotCode);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function createMovement(int $tenantId, array $data): OwnershipMovement
    {
        return OwnershipMovement::query()->create(['tenant_id' => $tenantId, ...$data]);
    }

    public function movements(int $tenantId, int $ownedItemId): Collection
    {
        return OwnershipMovement::query()->with('acquisitionLot')->where('tenant_id', $tenantId)
            ->where('owned_item_id', $ownedItemId)->orderBy('id')->get();
    }

    public function movementBalances(int $tenantId, int $ownedItemId): array
    {
        $totals = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->selectRaw('COALESCE(SUM(owned_delta), 0) as owned, COALESCE(SUM(pledged_delta), 0) as pledged')->first();
        return ['owned' => (float) $totals->owned, 'pledged' => (float) $totals->pledged];
    }

    public function lotBalances(int $tenantId, int $ownedItemId, int $lotId): array
    {
        $totals = DB::table('ownership_movements')->where('tenant_id', $tenantId)
            ->where('owned_item_id', $ownedItemId)->where('acquisition_lot_id', $lotId)
            ->selectRaw('COALESCE(SUM(owned_delta), 0) as owned, COALESCE(SUM(pledged_delta), 0) as pledged')->first();
        return ['owned' => (float) $totals->owned, 'pledged' => (float) $totals->pledged];
    }

    public function findBySourceCode(int $tenantId, string $module, string $type, string $sourceCode): ?AcquisitionLot
    {
        return AcquisitionLot::query()->where('tenant_id', $tenantId)->where('source_module', $module)
            ->where('source_type', $type)->where('source_code', $sourceCode)->first();
    }
}
