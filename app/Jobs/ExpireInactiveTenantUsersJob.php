<?php

namespace App\Jobs;

use App\Services\TenantModule\AuthService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExpireInactiveTenantUsersJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(AuthService $authService): void
    {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($authService): void {
            $authService->expireInactiveTenantUsers();
        });
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('expire-inactive-tenant-users'))->expireAfter(600),
        ];
    }
}
