<?php

namespace App\Jobs;

use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckExpireTenantLicenseJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(TenantLicenseService $tenantLicenseService): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($tenantLicenseService): void {
            $tenantLicenseService->checkExpire();
            $tenantLicenseService->sendExpiringSoonNotifications(7);
        });
    }
}
