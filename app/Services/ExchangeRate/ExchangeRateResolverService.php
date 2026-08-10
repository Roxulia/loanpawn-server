<?php

namespace App\Services\ExchangeRate;

use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;

class ExchangeRateResolverService
{
    public function resolve(ExchangeRatePair $pair, ?int $tenantId, string $date): ?ExchangeRateEntry
    {
        if ($tenantId) {
            $tenant = $this->latest("tenant:{$tenantId}", $pair->id, $date);
            if ($tenant) {
                return $tenant;
            }
        }

        return $this->latest('platform', $pair->id, $date);
    }

    private function latest(string $scopeKey, int $pairId, string $date): ?ExchangeRateEntry
    {
        return ExchangeRateEntry::query()->with('pair.baseCurrency', 'pair.quoteCurrency')->where('scope_key', $scopeKey)->where('exchange_rate_pair_id', $pairId)->whereDate('effective_date', '<=', $date)->where('is_void', false)->orderByDesc('effective_date')->orderByDesc('observed_at')->orderByDesc('id')->first();
    }
}
