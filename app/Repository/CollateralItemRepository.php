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
            ->with('materialType')
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

        return PawnCollateralItem::query()->create($data)->load('materialType');
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

        return $item->refresh()->load('materialType');
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
            ->with('materialType')
            ->find($itemId);
    }

    public function findByCode(string $code): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with('materialType')
            ->where('code', $code)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByIdWithLock(int $itemId): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with('materialType')
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with('materialType')
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
            ->with('materialType')
            ->where('loan_contract_id', $loanContractId)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();
    }

    public function findByLoanContractIdWithLock(int $loanContractId): Collection
    {
        return PawnCollateralItem::query()
            ->with('materialType')
            ->where('loan_contract_id', $loanContractId)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }


}
