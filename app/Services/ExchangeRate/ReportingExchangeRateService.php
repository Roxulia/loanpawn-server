<?php

namespace App\Services\ExchangeRate;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\ExchangeRatePairRepository;

class ReportingExchangeRateService
{
    public function __construct(
        private ExchangeRatePairRepository $repository,
        private ExchangeRateResolverService $resolver,
    ) {}

    public function conversion(int $tenantId, int $fromCurrencyId, int $toCurrencyId, string $date): ?array
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return ['rate' => 1.0, 'multiplier' => 1.0, 'direction' => 'same'];
        }

        foreach ($this->pairCandidates($tenantId, $fromCurrencyId, $toCurrencyId) as $candidate) {
            $pair = $candidate['pair'];
            $entry = $this->resolver->resolveExact($pair, $tenantId, $date);
            if ($entry !== null && (float) $entry->selling_rate > 0) {
                $sellingRate = (float) $entry->selling_rate;

                return [
                    'rate' => $sellingRate,
                    'multiplier' => $candidate['direction'] === 'direct' ? $sellingRate : 1 / $sellingRate,
                    'direction' => $candidate['direction'],
                    'pair_code' => $pair->code,
                    'entry_code' => $entry->code,
                    'source' => $entry->tenant_id === null ? 'platform' : 'tenant',
                ];
            }
        }

        return null;
    }

    public function pairForConversion(int $tenantId, int $fromCurrencyId, int $toCurrencyId): ?array
    {
        return $this->pairCandidates($tenantId, $fromCurrencyId, $toCurrencyId)[0] ?? null;
    }

    public function manualMultiplier(?float $rate, bool $inversed = false): ?float
    {
        if ($rate === null) {
            return null;
        }

        if ($rate <= 0) {
            throw new InvalidTenantRequest('Exchange rate must be greater than zero.');
        }

        return $inversed ? 1 / $rate : $rate;
    }

    private function pairCandidates(int $tenantId, int $fromCurrencyId, int $toCurrencyId): array
    {
        $candidates = [];

        foreach ([
            ['direction' => 'direct', 'base' => $fromCurrencyId, 'quote' => $toCurrencyId],
            ['direction' => 'reverse', 'base' => $toCurrencyId, 'quote' => $fromCurrencyId],
        ] as $candidate) {
            $pairs = $this->repository->visibleDirections($tenantId, $candidate['base'], $candidate['quote']);
            foreach ($pairs as $pair) {
                $candidates[] = ['direction' => $candidate['direction'], 'pair' => $pair];
            }
        }

        return $candidates;
    }
}
