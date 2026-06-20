<?php

namespace App\Jobs;

use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Support\OperationLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResetTenantLicenseMonthlySlipCountJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('scheduled');
    }

    public function handle(TenantLicenseService $tenantLicenseService): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($tenantLicenseService): void {
            $tenantLicenseService->resetCurrentMonthSlipCounts();
        });
    }
}
