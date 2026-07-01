<?php

namespace App\Observers;

use App\Jobs\Telegram\SendSupportTicketTelegramNotificationJob;
use App\Models\PlatformModule\PlatformSupportTicket;

class PlatformSupportTicketObserver
{
    public function created(PlatformSupportTicket $ticket): void
    {
        if (! config('services.telegram.notifications_enabled')) {
            return;
        }

        dispatch(new SendSupportTicketTelegramNotificationJob($ticket->id))->afterCommit();
    }
}
