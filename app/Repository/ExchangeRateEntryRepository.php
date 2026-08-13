<?php

namespace App\Repository;

use App\Models\CoreModule\ExchangeRateEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ExchangeRateEntryRepository
{
    public function visibleToTenant(int $tenantId, int $perPage = 50, ?string $pairCode = null): LengthAwarePaginator
    {
        return ExchangeRateEntry::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where('tenant_id', $tenantId)->when($pairCode, fn ($query) => $query->whereHas('pair', fn ($pair) => $pair->where('code', strtoupper($pairCode))))->latest('observed_at')->paginate($perPage);
    }

    public function platform(int $perPage = 50): LengthAwarePaginator
    {
        return ExchangeRateEntry::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->whereNull('tenant_id')->latest('observed_at')->paginate($perPage);
    }

    public function findVisible(string $code, ?int $tenantId): ?ExchangeRateEntry
    {
        return ExchangeRateEntry::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where('code', $code)->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))->first();
    }

    public function findOwned(string $code, ?int $tenantId): ?ExchangeRateEntry
    {
        return ExchangeRateEntry::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where('code', $code)->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->first();
    }

    public function activeForDay(string $scopeKey, int $pairId, string $date): Collection
    {
        return ExchangeRateEntry::query()->where('scope_key', $scopeKey)->where('exchange_rate_pair_id', $pairId)->whereDate('effective_date', $date)->where('is_void', false)->orderBy('observed_at')->orderBy('id')->get();
    }

    public function latestActiveForDay(string $scopeKey, int $pairId, string $date): ?ExchangeRateEntry
    {
        return ExchangeRateEntry::query()->where('scope_key', $scopeKey)->where('exchange_rate_pair_id', $pairId)->whereDate('effective_date', $date)->where('is_void', false)->latest('observed_at')->latest('id')->first();
    }

    public function summaryTargetsBetween(string $fromDate, string $toDate): Collection
    {
        return ExchangeRateEntry::query()
            ->select(['tenant_id', 'scope_key', 'exchange_rate_pair_id', 'effective_date'])
            ->whereBetween('effective_date', [$fromDate, $toDate])
            ->distinct()
            ->get();
    }

    public function create(array $data): ExchangeRateEntry
    {
        return ExchangeRateEntry::query()->create($data)->load('pair.baseCurrency', 'pair.quoteCurrency')->refresh();
    }
}
