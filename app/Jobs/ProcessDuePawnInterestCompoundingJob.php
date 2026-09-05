<?php

namespace App\Jobs;

use App\Services\PawnModule\PawnInterestProcessService;
use App\Services\TenantModule\DebtInterestFlowService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessDuePawnInterestCompoundingJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(PawnInterestProcessService $service, DebtInterestFlowService $debtInterestFlowService): void
    {
        app(OperationLogger::class)->run(
            self::class.'::handle',
            function () use ($service, $debtInterestFlowService): void {
                $service->processDueSchedules();
                $debtInterestFlowService->processDueSchedules();
            },
        );
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('process-due-pawn-interest-compounding'))->expireAfter(1800),
        ];
    }
}
