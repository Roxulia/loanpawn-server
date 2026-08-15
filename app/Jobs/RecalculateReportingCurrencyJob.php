<?php

namespace App\Jobs;

use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RecalculateReportingCurrencyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $recalculationId)
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(ReportingCurrencyRecalculationService $service): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', fn () => $service->process($this->recalculationId));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('reporting-currency-recalculation-'.$this->recalculationId))->expireAfter(7200)];
    }

    public function failed(Throwable $exception): void
    {
        app(ReportingCurrencyRecalculationService::class)->markFailed($this->recalculationId, $exception->getMessage());
    }
}
