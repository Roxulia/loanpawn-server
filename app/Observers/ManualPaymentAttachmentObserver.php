<?php

namespace App\Observers;

use App\Jobs\Telegram\SendPaymentScreenshotTelegramNotificationJob;
use App\Models\PlatformModule\ManualPaymentAttachment;

class ManualPaymentAttachmentObserver
{
    public function created(ManualPaymentAttachment $attachment): void
    {
        if (! config('services.telegram.notifications_enabled')) {
            return;
        }

        dispatch(new SendPaymentScreenshotTelegramNotificationJob($attachment->id))->afterCommit();
    }
}
