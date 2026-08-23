<?php

namespace App\Jobs;

use App\Services\TenantModule\TenantUserNotificationService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PurgeExpiredTenantUserNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(TenantUserNotificationService $service): void
    {
        app(OperationLogger::class)->run(
            self::class.'::handle',
            fn () => $service->purgeExpired(),
        );
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('purge-expired-tenant-user-notifications'))->expireAfter(3600)];
    }
}
