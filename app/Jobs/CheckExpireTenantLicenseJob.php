<?php

namespace App\Jobs;

use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckExpireTenantLicenseJob implements ShouldQueue
{
    use Queueable;

    public function handle(TenantLicenseService $tenantLicenseService): void
    {
        $tenantLicenseService->checkExpire();
        $tenantLicenseService->sendExpiringSoonNotifications(7);
    }
}
