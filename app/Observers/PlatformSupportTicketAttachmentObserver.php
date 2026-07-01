<?php

namespace App\Observers;

use App\Jobs\Telegram\SendSupportTicketAttachmentTelegramNotificationJob;
use App\Models\PlatformModule\PlatformSupportTicketAttachment;

class PlatformSupportTicketAttachmentObserver
{
    public function created(PlatformSupportTicketAttachment $attachment): void
    {
        if (! config('services.telegram.notifications_enabled')) {
            return;
        }

        if ($attachment->uploaded_by_type !== 'platform_user') {
            return;
        }

        dispatch(new SendSupportTicketAttachmentTelegramNotificationJob($attachment->id))->afterCommit();
    }
}
