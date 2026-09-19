<?php

namespace App\Jobs;

use App\Services\PawnModule\PawnInterestProcessService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessDuePawnInterestAccrualsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // Use the configured queue connection and the scheduled-work queue.
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(PawnInterestProcessService $service): void
    {
        // Record the scheduled operation through the shared operation logger.
        app(OperationLogger::class)->run(
            self::class.'::handle',
            fn () => $service->processDueInterestAccruals(),
        );
    }

    public function middleware(): array
    {
        // Prevent concurrent scheduler runs from materializing the same slips.
        return [
            (new WithoutOverlapping('process-due-pawn-interest-accruals'))->expireAfter(1800),
        ];
    }
}
