<?php

namespace App\Repository;

use App\Models\PlatformModule\PlatformAdmin;

class PlatformAdminRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findByEmail(string $email) : ?PlatformAdmin
    {
        $res = PlatformAdmin::query()->where('email',$email)->first();
        return $res;
    }

    public function findActiveByTelegramChatId(string $telegramChatId): ?PlatformAdmin
    {
        return PlatformAdmin::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->first();
    }

    public function activeTelegramAdmins()
    {
        return PlatformAdmin::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->get();
    }
}
