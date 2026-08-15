<?php

namespace App\Services\ExchangeRate;

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

        foreach ([
            ['direction' => 'direct', 'base' => $fromCurrencyId, 'quote' => $toCurrencyId],
            ['direction' => 'reverse', 'base' => $toCurrencyId, 'quote' => $fromCurrencyId],
        ] as $candidate) {
            $pairs = $this->repository->visibleDirections($tenantId, $candidate['base'], $candidate['quote']);
            foreach ($pairs as $pair) {
                $entry = $this->resolver->resolveExact($pair, $tenantId, $date);
                if ($entry !== null && (float) $entry->selling_rate > 0) {
                    $sellingRate = (float) $entry->selling_rate;

                    return [
                        'rate' => $sellingRate,
                        'multiplier' => $candidate['direction'] === 'direct' ? $sellingRate : 1 / $sellingRate,
                        'direction' => $candidate['direction'],
                        'pair_code' => $pair->code,
                        'entry_code' => $entry->code,
                    ];
                }
            }
        }

        return null;
    }
}
