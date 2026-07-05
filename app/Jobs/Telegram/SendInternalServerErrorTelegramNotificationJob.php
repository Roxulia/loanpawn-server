<?php

namespace App\Jobs\Telegram;

use App\Services\PlatformModule\Telegram\PlatformAdminTelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInternalServerErrorTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private array $context,
    ) {
        $this->onQueue(config('services.telegram.queue', 'default'));
    }

    public function handle(PlatformAdminTelegramNotificationService $notificationService): void
    {
        $notificationService->sendInternalServerError($this->context);
    }

    public function context(): array
    {
        return $this->context;
    }
}
