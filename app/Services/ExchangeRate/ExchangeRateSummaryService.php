<?php

namespace App\Services\ExchangeRate;

use App\Repository\ExchangeRateEntryRepository;

class ExchangeRateSummaryService
{
    public function __construct(
        private ExchangeRateEntryRepository $entries,
        private DailyExchangeRateSummaryPersistenceService $summaries,
        private ExchangeRateBusinessClock $clock,
    ) {}

    public function rebuild(string $scopeKey, ?int $tenantId, int $pairId, string $date): void
    {
        $identity = ['scope_key' => $scopeKey, 'exchange_rate_pair_id' => $pairId, 'rate_date' => $date];
        $entries = $this->entries->activeForDay($scopeKey, $pairId, $date);
        if ($entries->isEmpty()) {
            $this->summaries->delete($identity);

            return;
        }
        $first = $entries->first();
        $last = $entries->last();
        $this->summaries->upsert($identity, ['tenant_id' => $tenantId, 'buying_open' => $first->buying_rate, 'buying_high' => $entries->max('buying_rate'), 'buying_low' => $entries->min('buying_rate'), 'buying_close' => $last->buying_rate, 'selling_open' => $first->selling_rate, 'selling_high' => $entries->max('selling_rate'), 'selling_low' => $entries->min('selling_rate'), 'selling_close' => $last->selling_rate, 'entry_count' => $entries->count(), 'first_entry_id' => $first->id, 'last_entry_id' => $last->id, 'calculated_at' => $this->clock->now($tenantId)->utc()]);
    }
}
