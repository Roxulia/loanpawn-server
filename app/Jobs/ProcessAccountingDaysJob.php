<?php

namespace App\Jobs;

use App\Services\TenantModule\TenantAccountingDayService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessAccountingDaysJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(TenantAccountingDayService $service): void
    {
        app(OperationLogger::class)->run(
            self::class.'::handle',
            fn () => $service->processAutomation(),
        );
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('process-tenant-accounting-days'))->expireAfter(1800),
        ];
    }
}
