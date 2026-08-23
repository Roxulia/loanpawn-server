<?php

namespace App\Services\ExchangeRate;

use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\ExchangeRateEntryRepository;

class ExchangeRateResolverService
{
    public function __construct(private ExchangeRateEntryRepository $entries) {}

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
        return $this->entries->latestActiveOnOrBefore($scopeKey, $pairId, $date);
    }

    public function resolveExact(ExchangeRatePair $pair, int $tenantId, string $date): ?ExchangeRateEntry
    {
        return $this->entries->exactForTenantThenPlatform($tenantId, $pair->id, $date);
    }
}
