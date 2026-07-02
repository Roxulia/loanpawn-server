<?php

namespace App\Repository;

use App\Models\PawnModule\PawnCollateralItem;

class PawnCollateralItemRepository
{
    public function create(array $data): PawnCollateralItem
    {
        return PawnCollateralItem::query()->create($data)->load(['materialType', 'itemCategoryType']);
    }

    public function findById(int $itemId): ?PawnCollateralItem
    {
        return PawnCollateralItem::query()
            ->with(['materialType', 'itemCategoryType'])
            ->find($itemId);
    }

    public function update(PawnCollateralItem $item, array $data): PawnCollateralItem
    {
        $item->update($data);

        return $item->refresh()->load(['materialType', 'itemCategoryType']);
    }

    public function delete(PawnCollateralItem $item): void
    {
        $item->delete();
    }
}
