<?php

namespace App\Services\TenantModule\Ownership;

use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\InventoryService;
use Illuminate\Support\Collection;

/** Internal complete-set locks retained by the outer business transaction. */
class LockService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private InventoryService $inventoryService) {}

    public function items(array $inventoryItemCodes): Collection
    {
        // Inventory validates and locks the entire tenant-bound set in its established ID order.
        $items = $this->inventoryService->prelockItems($inventoryItemCodes);
        $tenant = $this->resolveCurrentTenantId();
        $owners = $this->repository->lockedAggregates($tenant, $items->pluck('id')->all());

        // Missing aggregates are valid on first acquisition; the Inventory identity serializes creation.
        $this->repository->lockedLotsForItems($tenant, $owners->pluck('id')->all());
        return $owners->keyBy('inventory_item_id');
    }
}
