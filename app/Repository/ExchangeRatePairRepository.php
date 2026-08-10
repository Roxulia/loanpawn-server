<?php

namespace App\Repository;

use App\Models\CoreModule\ExchangeRatePair;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ExchangeRatePairRepository
{
    private function baseQuery()
    {
        return ExchangeRatePair::query()->with(['baseCurrency', 'quoteCurrency']);
    }

    public function visibleToTenant(int $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        return $this->baseQuery()->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->orderBy('code')->paginate($perPage);
    }

    public function platform(int $perPage = 50): LengthAwarePaginator
    {
        return $this->baseQuery()->whereNull('tenant_id')->orderBy('code')->paginate($perPage);
    }

    public function findVisible(string $code, ?int $tenantId): ?ExchangeRatePair
    {
        return $this->baseQuery()->where('code', strtoupper($code))->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))->orderByRaw('tenant_id IS NULL')->first();
    }

    public function findOwned(string $code, ?int $tenantId): ?ExchangeRatePair
    {
        return $this->baseQuery()->where('code', strtoupper($code))->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->first();
    }

    public function directionExistsForTenant(int $tenantId, int $baseId, int $quoteId, ?int $ignoreId = null): bool
    {
        return ExchangeRatePair::query()->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->where('base_currency_id', $baseId)->where('quote_currency_id', $quoteId)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }

    public function create(array $data): ExchangeRatePair
    {
        return ExchangeRatePair::query()->create($data)->load(['baseCurrency', 'quoteCurrency']);
    }
}
