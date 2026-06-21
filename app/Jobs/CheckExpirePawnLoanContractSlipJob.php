<?php

namespace App\Jobs;

use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Support\OperationLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckExpirePawnLoanContractSlipJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('scheduled');
    }

    public function handle(ExpirationService $expirationService): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($expirationService): void {
            $expirationService->checkExpire();
        });
    }
}
