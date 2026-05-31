<?php

namespace Database\Seeders;

use App\Models\PlatformModule\LicenseStatusLog;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Models\PlatformModule\TenantStatusLog;
use Illuminate\Database\Seeder;

class PlatformAccessSeeder extends Seeder
{
    public function run(): void
    {
        $platformAdmin = PlatformAdmin::query()->updateOrCreate(
            ['email' => 'admin@lonepawn.test'],
            [
                'code' => '202604001',
                'name' => 'Demo Platform Admin',
                'username' => 'platformadmin',
                'password' => 'password',
                'status' => 'active',
            ]
        );

        $platformUser = PlatformUser::query()->updateOrCreate(
            ['email' => 'owner@lonepawn.test'],
            [
                'code' => '202604001',
                'name' => 'Demo Platform User',
                'phone' => '09111111111',
                'password' => 'password',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $tenant = Tenant::query()->updateOrCreate(
            ['tenant_code' => 'DEMO001'],
            [
                'platform_user_id' => $platformUser->id,
                'name' => 'Demo Premium Pawnshop',
                'subdomain' => 'demo-premium',
                'status' => 'active',
            ]
        );

        $license = TenantLicense::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'license_key' => 'PREMIUMDEMO0001',
                'plan_type' => 'premium',
                'status' => 'active',
                'starts_at' => now()->subDays(7),
                'expires_at' => now()->addYear(),
                'activated_at' => now()->subDays(7),
                'approved_by' => $platformAdmin->id,
                'notes' => 'Seeded premium tenant for local development.',
            ]
        );

        TenantStatusLog::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'new_status' => 'active',
            ],
            [
                'old_status' => null,
                'changed_by' => $platformAdmin->id,
                'reason' => 'Seeded active premium tenant.',
            ]
        );

        LicenseStatusLog::query()->updateOrCreate(
            [
                'license_id' => $license->id,
                'new_status' => 'active',
            ],
            [
                'old_status' => null,
                'changed_by' => $platformAdmin->id,
                'reason' => 'Seeded premium license.',
            ]
        );
    }
}
