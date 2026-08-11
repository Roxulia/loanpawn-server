<?php

namespace App\Repository;

use App\Models\CoreModule\DailyExchangeRateSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DailyExchangeRateSummaryRepository
{
    public function find(array $identity): ?DailyExchangeRateSummary
    {
        return DailyExchangeRateSummary::query()
            ->where('scope_key', $identity['scope_key'])
            ->where('exchange_rate_pair_id', $identity['exchange_rate_pair_id'])
            ->whereDate('rate_date', $identity['rate_date'])
            ->first();
    }

    public function upsert(array $identity, array $values): DailyExchangeRateSummary
    {
        $summary = $this->find($identity);

        if ($summary) {
            $summary->update($values);

            return $summary->refresh();
        }

        return DailyExchangeRateSummary::query()->create($identity + $values);
    }

    public function delete(array $identity): void
    {
        DailyExchangeRateSummary::query()
            ->where('scope_key', $identity['scope_key'])
            ->where('exchange_rate_pair_id', $identity['exchange_rate_pair_id'])
            ->whereDate('rate_date', $identity['rate_date'])
            ->delete();
    }

    public function visibleToTenant(int $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        return DailyExchangeRateSummary::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->latest('rate_date')->paginate($perPage);
    }

    public function platform(int $perPage = 50): LengthAwarePaginator
    {
        return DailyExchangeRateSummary::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->whereNull('tenant_id')->latest('rate_date')->paginate($perPage);
    }

    public function closingTrend(int $tenantId, int $pairId, string $fromDate, string $toDate): array
    {
        return DailyExchangeRateSummary::query()
            ->whereIn('scope_key', ['platform', "tenant:{$tenantId}"])
            ->where('exchange_rate_pair_id', $pairId)
            ->whereBetween('rate_date', [$fromDate, $toDate])
            ->orderBy('rate_date')
            ->get()
            ->groupBy(fn (DailyExchangeRateSummary $summary) => $summary->tenant_id === null ? 'platform' : 'tenant')
            ->map(fn ($items) => $items->map(fn (DailyExchangeRateSummary $summary) => [
                'date' => $summary->rate_date->toDateString(),
                'buying_close' => $summary->buying_close,
                'selling_close' => $summary->selling_close,
            ])->values()->all())
            ->all();
    }
}
