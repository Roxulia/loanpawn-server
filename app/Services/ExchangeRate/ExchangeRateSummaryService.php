<?php

namespace App\Services\ExchangeRate;

use App\Repository\DailyExchangeRateSummaryRepository;
use App\Repository\ExchangeRateEntryRepository;

class ExchangeRateSummaryService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private DailyExchangeRateSummaryRepository $summaries) {}

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
        $this->summaries->upsert($identity, ['tenant_id' => $tenantId, 'open_rate' => $first->rate, 'high_rate' => $entries->max('rate'), 'low_rate' => $entries->min('rate'), 'close_rate' => $last->rate, 'entry_count' => $entries->count(), 'first_entry_id' => $first->id, 'last_entry_id' => $last->id, 'calculated_at' => now()]);
    }
}
