<?php

namespace App\Jobs;

use App\Services\ExchangeRate\ExchangeRateSummaryService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshDailyExchangeRateSummariesJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(ExchangeRateSummaryService $summaries): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($summaries): void {
            $summaries->refreshCurrentBusinessDays();
        });
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('refresh-daily-exchange-rate-summaries'))->expireAfter(7200),
        ];
    }
}
