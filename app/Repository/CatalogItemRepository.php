<?php

namespace App\Repository;

use App\Models\CatalogModule\CatalogItem;
use Illuminate\Database\Eloquent\Collection;

class CatalogItemRepository
{
    public function search(int $tenantId, ?string $search): Collection
    {
        return CatalogItem::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($search, function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->with(['category:id,name', 'unit:id,code,name,symbol'])
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    public function findForTenant(int $tenantId, int $id, bool $forUpdate = false): ?CatalogItem
    {
        $query = CatalogItem::query()->where('tenant_id', $tenantId);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->find($id);
    }

    public function findByBusinessCode(int $tenantId, string $businessCode): ?CatalogItem
    {
        return CatalogItem::query()->where('tenant_id', $tenantId)->where('business_code', $businessCode)->first();
    }

    public function create(int $tenantId, array $data): CatalogItem
    {
        return CatalogItem::query()->create($data + ['tenant_id' => $tenantId]);
    }

    public function update(CatalogItem $item, array $data): CatalogItem
    {
        $item->fill($data);
        $item->update_key++;
        $item->save();

        return $item;
    }
}
