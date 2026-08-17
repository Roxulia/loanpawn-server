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

    public function updatePasswordCredentials(PlatformAdmin $platformAdmin, string $passwordHash): PlatformAdmin
    {
        $platformAdmin->forceFill([
            'password' => $passwordHash,
            'remember_token' => null,
            'update_key' => (int) $platformAdmin->update_key + 1,
        ])->save();

        return $platformAdmin->refresh();
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
