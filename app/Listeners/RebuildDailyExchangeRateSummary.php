<?php

namespace App\Listeners;

use App\Events\ExchangeRateChanged;
use App\Services\ExchangeRate\ExchangeRateSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RebuildDailyExchangeRateSummary implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private ExchangeRateSummaryService $summaries) {}

    public function handle(ExchangeRateChanged $event): void
    {
        $this->summaries->rebuild(
            $event->scopeKey,
            $event->tenantId,
            $event->pairId,
            $event->effectiveDate,
        );
    }

    public function middleware(ExchangeRateChanged $event): array
    {
        return [
            (new WithoutOverlapping($this->lockKey($event)))
                ->releaseAfter(1)
                ->expireAfter(300),
        ];
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    private function lockKey(ExchangeRateChanged $event): string
    {
        return implode(':', [
            'exchange-rate-summary',
            $event->scopeKey,
            $event->pairId,
            $event->effectiveDate,
        ]);
    }
}
