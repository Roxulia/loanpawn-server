<?php

namespace App\Repository;

use App\Models\PawnModule\PawnCollateralItem;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CollateralItemRepository
{
    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->where('is_deleted', false)
            ->orderByDesc('created_at');

        if ($search !== null) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('item_status', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function create(array $data): PawnCollateralItem
    {
        $this->requireValue($data, 'code');

        return PawnCollateralItem::query()->create($data)->load(['materialType', 'itemCategoryType']);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Collateral item {$key} is required.");
        }
    }

    public function update(PawnCollateralItem $item, array $data): PawnCollateralItem
    {
        $item->update($data);

        return $item->refresh()->load(['materialType', 'itemCategoryType']);
    }

    public function saveSubItems(PawnCollateralItem $item, array $rows): void
    {
        // Child IDs have already been checked against the locked parent by the service.
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            unset($row['id']);
            $row['tenant_id'] = $item->tenant_id;
            if ($id === null) {
                $item->subItems()->create($row);
            } else {
                $item->subItems()->whereKey($id)->firstOrFail()->update($row);
            }
        }
    }

    public function updateWithLock(PawnCollateralItem $item, array $data): PawnCollateralItem
    {
        $lockedItem = PawnCollateralItem::query()
            ->whereKey($item->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedItem, $data);
    }

    public function delete(PawnCollateralItem $item): void
    {
        $item->delete();
    }

    public function findById(int $itemId): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->find($itemId);
    }

    public function findByCode(string $code): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->where('code', $code)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByIdWithLock(int $itemId): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->where('code', $code)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return Collection<int, PawnCollateralItem>
     */
    public function findByLoanContractId(int $loanContractId): Collection
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->where('loan_contract_id', $loanContractId)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();
    }

    public function findByLoanContractIdWithLock(int $loanContractId): Collection
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->where('loan_contract_id', $loanContractId)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }



    public function expiredForOwnershipTransfer(?string $search = null): Collection
    {
        // Load eligible collateral with slip, customer, currency, and linked Inventory context.
        return PawnCollateralItem::query()
            ->with(['inventoryItem.unit', 'loanContract.customer', 'loanContract.account.currency', 'loanContract.slipItems'])
            ->where('is_deleted', false)
            ->whereRaw('LOWER(item_status) = ?', ['expired'])
            ->whereNull('ownership_transferred_at')
            ->whereHas('loanContract', fn ($query) => $query->where('is_deleted', false)->whereRaw('LOWER(status) = ?', ['expired']))
            ->when($search !== null, function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhereHas('loanContract', fn ($slip) => $slip->where('slip_no', 'like', '%'.$search.'%')
                            ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%')));
                });
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    public function countCollateralMissingInventoryLink(?int $tenantId = null): int
    {
        // Count only collateral that remains in tenant custody under an active or expired slip.
        return $this->collateralMissingInventoryLinkQuery($tenantId)->count();
    }

    public function collateralMissingInventoryLinkBatch(?int $tenantId, int $afterId, int $limit): Collection
    {
        // Load a bounded batch so large tenant histories do not fill command memory.
        return $this->collateralMissingInventoryLinkQuery($tenantId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    protected function collateralMissingInventoryLinkQuery(?int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        // Restrict the backfill to unlinked, untransferred items attached to live custody slips.
        $query = PawnCollateralItem::query()
            ->where('is_deleted', false)
            ->whereNull('inventory_item_id')
            ->whereNull('ownership_transferred_at')
            ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(item_status)'), ['active', 'expired'])
            ->whereHas('loanContract', function ($slipQuery) use ($tenantId): void {
                $slipQuery->where('is_deleted', false)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(status)'), ['active', 'expired'])
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
            });

        if ($tenantId === null) {
            $query->withoutGlobalScope('tenant');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }
    public function expireForExpiredSlips(?int $tenantId = null, bool $dryRun = false): int
    {
        // Persist expired collateral status using the parent slip lifecycle as its source of truth.
        $query = PawnCollateralItem::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(item_status) = ?', ['active'])
            ->whereHas('loanContract', function ($slipQuery) use ($tenantId): void {
                $slipQuery->where('is_deleted', false)
                    ->whereRaw('LOWER(status) = ?', ['expired'])
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
            });

        if ($tenantId === null) {
            $query->withoutGlobalScope('tenant');
        }

        return $dryRun ? $query->count() : $query->update(['item_status' => 'expired']);
    }

    public function expireForSlip(int $slipId): int
    {
        // Expire only active collateral belonging to one overdue slip.
        return PawnCollateralItem::query()
            ->where('loan_contract_id', $slipId)
            ->where('is_deleted', false)
            ->whereRaw('LOWER(item_status) = ?', ['active'])
            ->update(['item_status' => 'expired']);
    }
}
