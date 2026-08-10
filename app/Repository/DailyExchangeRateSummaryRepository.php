<?php

namespace App\Repository;

use App\Models\CoreModule\DailyExchangeRateSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DailyExchangeRateSummaryRepository
{
    public function upsert(array $identity, array $values): DailyExchangeRateSummary
    {
        return DailyExchangeRateSummary::query()->updateOrCreate($identity, $values);
    }

    public function delete(array $identity): void
    {
        DailyExchangeRateSummary::query()->where($identity)->delete();
    }

    public function visibleToTenant(int $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        return DailyExchangeRateSummary::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->latest('rate_date')->paginate($perPage);
    }

    public function platform(int $perPage = 50): LengthAwarePaginator
    {
        return DailyExchangeRateSummary::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->whereNull('tenant_id')->latest('rate_date')->paginate($perPage);
    }
}
