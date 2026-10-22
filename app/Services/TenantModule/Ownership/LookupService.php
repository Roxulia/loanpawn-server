<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\TenantNotFound;
use App\Models\OwnershipModule\OwnedItem;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;

/** Canonical Ownership resource resolution shared by allocation and bounded reads. */
class LookupService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository) {}

    public function byInventoryId(int $id, bool $lock = false): OwnedItem
    {
        // Inventory existence is resolved by InventoryService before this Ownership-specific lookup.
        return $this->repository->ownedItemForInventory($this->resolveCurrentTenantId(), $id, $lock)
            ?? throw new TenantNotFound(__('ownership.not_found'));
    }

    public function byCode(string $code, bool $lock = false): OwnedItem
    {
        // Resolve without loading an unbounded acquisition-lot relationship.
        return $this->repository->findOwnedByCode($this->resolveCurrentTenantId(), $code, $lock)
            ?? throw new TenantNotFound(__('ownership.not_found'));
    }
}
