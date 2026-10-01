<?php

namespace App\Repository;

use App\Models\CatalogModule\CatalogCategory;
use App\Models\CatalogModule\CatalogUnit;
use Illuminate\Database\Eloquent\Collection;

class CatalogTaxonomyRepository
{
    public function categories(int $tenantId): Collection
    {
        return CatalogCategory::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();
    }

    public function findCategory(int $tenantId, int $id): ?CatalogCategory
    {
        return CatalogCategory::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    public function findCategoryByName(int $tenantId, string $name): ?CatalogCategory
    {
        return CatalogCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();
    }

    public function createCategory(int $tenantId, array $data): CatalogCategory
    {
        $data['tenant_id'] = $tenantId;

        return CatalogCategory::query()->create($data);
    }

    public function updateCategory(CatalogCategory $category, array $data): CatalogCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function units(int $tenantId): Collection
    {
        return CatalogUnit::query()
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->where('is_active', true)
            ->orderByRaw('tenant_id IS NULL DESC')
            ->orderBy('name')
            ->get();
    }

    public function unitsForManagement(int $tenantId): Collection
    {
        return CatalogUnit::query()
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->orderByRaw('tenant_id IS NULL DESC')
            ->orderBy('name')
            ->get();
    }

    public function tenantUnits(int $tenantId): Collection
    {
        return CatalogUnit::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();
    }

    public function findUnit(int $tenantId, int $id): ?CatalogUnit
    {
        return CatalogUnit::query()
            ->where('id', $id)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->first();
    }

    public function findTenantUnit(int $tenantId, int $id): ?CatalogUnit
    {
        return CatalogUnit::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    public function findTenantUnitByCode(int $tenantId, string $code): ?CatalogUnit
    {
        return CatalogUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();
    }

    public function createUnit(int $tenantId, array $data): CatalogUnit
    {
        $data['tenant_id'] = $tenantId;
        $data['scope_key'] = 'tenant:'.$tenantId;

        return CatalogUnit::query()->create($data);
    }

    public function updateUnit(CatalogUnit $unit, array $data): CatalogUnit
    {
        $unit->update($data);

        return $unit->refresh();
    }

    public function platformUnits(): Collection
    {
        return CatalogUnit::query()
            ->whereNull('tenant_id')
            ->orderBy('name')
            ->get();
    }

    public function findPlatformUnitByCode(string $code): ?CatalogUnit
    {
        return CatalogUnit::query()
            ->whereNull('tenant_id')
            ->where('code', $code)
            ->first();
    }

    public function findPlatformUnit(int $id): ?CatalogUnit
    {
        return CatalogUnit::query()
            ->whereNull('tenant_id')
            ->find($id);
    }

    public function createPlatformUnit(array $data): CatalogUnit
    {
        $data['tenant_id'] = null;
        $data['scope_key'] = 'platform';

        return CatalogUnit::query()->create($data);
    }
}
