<?php

namespace App\Jobs\Telegram;

use App\Services\PlatformModule\Telegram\PlatformAdminTelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTenantRequestTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $tenantRequestId,
        private string $event,
    ) {
        $this->onQueue(config('services.telegram.queue', 'default'));
    }

    public function handle(PlatformAdminTelegramNotificationService $notificationService): void
    {
        match ($this->event) {
            'created' => $notificationService->sendTenantRequestCreated($this->tenantRequestId),
            'updated' => $notificationService->sendTenantRequestUpdated($this->tenantRequestId),
            default => null,
        };
    }
}
