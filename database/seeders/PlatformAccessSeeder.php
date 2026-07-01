<?php

namespace Database\Seeders;

use App\Models\PlatformModule\PlatformAdmin;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlatformAccessSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL');
        $password = env('PLATFORM_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException('PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD are required for platform admin seeding.');
        }

        PlatformAdmin::query()->updateOrCreate(
            ['email' => $email],
            [
                'code' => env('PLATFORM_ADMIN_CODE', '202604001'),
                'name' => env('PLATFORM_ADMIN_NAME', 'Platform Admin'),
                'username' => env('PLATFORM_ADMIN_USERNAME', 'platformadmin'),
                'telegram_chat_id' => env('PLATFORM_ADMIN_TELEGRAM_CHAT_ID'),
                'password' => $password,
                'status' => 'active',
            ]
        );
    }
}
