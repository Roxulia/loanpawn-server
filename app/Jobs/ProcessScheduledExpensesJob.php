<?php

namespace App\Jobs;

use App\Services\TenantModule\TenantScheduledExpenseService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessScheduledExpensesJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(TenantScheduledExpenseService $service): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', fn () => $service->processDueSchedules());
    }

    public function middleware(): array
    {
        // Prevent overlapping scans from competing for the same due occurrence.
        return [(new WithoutOverlapping('process-scheduled-expenses'))->expireAfter(1800)];
    }
}
