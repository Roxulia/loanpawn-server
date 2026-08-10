<?php

namespace App\Repository;

use App\Models\CoreModule\Currency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CurrencyRepository
{
    public function visibleToTenant(int $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        return Currency::query()->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->orderBy('code')->paginate($perPage);
    }

    public function activeVisibleToTenant(int $tenantId): Collection
    {
        return Currency::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->orderBy('code')->get();
    }

    public function platform(int $perPage = 50): LengthAwarePaginator
    {
        return Currency::query()->whereNull('tenant_id')->orderBy('code')->paginate($perPage);
    }

    public function findVisible(string $code, ?int $tenantId): ?Currency
    {
        return Currency::query()->where('code', strtoupper($code))->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))->orderByRaw('tenant_id IS NULL')->first();
    }

    public function findOwned(string $code, ?int $tenantId): ?Currency
    {
        return Currency::query()->where('code', strtoupper($code))->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->first();
    }

    public function create(array $data): Currency
    {
        return Currency::query()->create($data);
    }
}
