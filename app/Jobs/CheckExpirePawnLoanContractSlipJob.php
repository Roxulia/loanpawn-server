<?php

namespace App\Jobs;

use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckExpirePawnLoanContractSlipJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(ExpirationService $expirationService): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($expirationService): void {
            $expirationService->checkExpire();
        });
    }
}
