<?php

namespace App\Jobs\Telegram;

use App\Services\PlatformModule\Telegram\PlatformAdminTelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSupportTicketTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $ticketId)
    {
        $this->onQueue(config('services.telegram.queue', 'default'));
    }

    public function handle(PlatformAdminTelegramNotificationService $notificationService): void
    {
        $notificationService->sendSupportTicketCreated($this->ticketId);
    }
}
