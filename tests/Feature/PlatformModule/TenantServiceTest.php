<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\TenantCreate;
use App\Models\CoreModule\TenantRole;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_user_can_create_trial_tenant_with_active_license(): void
    {
        Carbon::setTestNow('2026-04-16 09:00:00');
        $this->createDefaultAdminRole();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Demo User',
            'email' => 'user@example.com',
            'phone' => '09250647303',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Demo Tenant',
            code: null,
            subdomain: 'demo-subdomain',
            createdByAdmin: false,
            planType: null,
            address: 'No. 1 Main Road',
            phone: '09123456789',
            city: 'Yangon',
            country: 'Myanmar',
        ));

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant',
            'tenant_code' => $tenant->tenant_code,
            'subdomain' => null,
            'status' => 'active',
        ]);
        $this->assertSame('DEM07EB', $tenant->tenant_code);

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'trial',
            'status' => 'active',
            'approved_by' => null,
        ]);

        $this->assertDatabaseHas('tenant_contacts', [
            'tenant_id' => $tenant->id,
            'address' => 'No. 1 Main Road',
            'phone' => '09123456789',
            'city' => 'Yangon',
            'country' => 'Myanmar',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'name' => 'Demo User',
            'username' => 'DU303746',
            'phone' => '09250647303',
            'nrc' => 'OWNER-'.$tenant->id,
        ]);

        $this->assertSame('trial', $tenant->license->plan_type);
        $this->assertSame('active', $tenant->license->status);
        $this->assertSame('2026-08-16 09:00:00', $tenant->license->expires_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_platform_user_generated_tenant_code_is_unique_for_same_tenant_name(): void
    {
        Carbon::setTestNow('2026-04-16 09:00:00');
        $this->createDefaultAdminRole();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Demo User',
            'email' => 'user@example.com',
            'phone' => '09250647303',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $firstTenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Demo Tenant',
            code: null,
            subdomain: null,
            createdByAdmin: false,
            planType: null,
        ));

        $secondTenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Demo Tenant',
            code: null,
            subdomain: null,
            createdByAdmin: false,
            planType: null,
        ));

        $this->assertSame('DEM07EB', $firstTenant->tenant_code);
        $this->assertSame('DEM07EC', $secondTenant->tenant_code);
        $this->assertNotSame($firstTenant->tenant_code, $secondTenant->tenant_code);

        Carbon::setTestNow();
    }

    public function test_platform_admin_can_create_tenant_with_custom_plan_and_status(): void
    {
        Carbon::setTestNow('2026-04-16 09:00:00');
        $this->createDefaultAdminRole();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Tenant Owner',
            'email' => 'owner@example.com',
            'phone' => '09987654321',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $platformAdmin = PlatformAdmin::query()->create([
            'code' => 'PA'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Platform Admin',
            'username' => 'platform-admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformAdmin, 'platformadmin');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Premium Tenant',
            code: 'premium-tenant',
            subdomain: 'premium-subdomain',
            createdByAdmin: true,
            planType: 'premium',
            status: 'suspended',
            platformUserId: $platformUser->id,
            expireAt: '2026-12-31 00:00:00',
            notes: 'Created by admin',
            address: 'No. 99 Admin Street',
            phone: '09987654321',
            city: 'Mandalay',
            country: 'Myanmar',
        ));

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'platform_user_id' => $platformUser->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'premium',
            'status' => 'suspended',
            'approved_by' => $platformAdmin->id,
            'notes' => 'Created by admin',
        ]);

        $this->assertDatabaseHas('tenant_contacts', [
            'tenant_id' => $tenant->id,
            'address' => 'No. 99 Admin Street',
            'phone' => '09987654321',
            'city' => 'Mandalay',
            'country' => 'Myanmar',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'name' => 'Tenant Owner',
            'username' => 'TO123456',
            'phone' => '09987654321',
            'nrc' => 'OWNER-'.$tenant->id,
        ]);

        $this->assertSame('premium', $tenant->license->plan_type);
        $this->assertSame('suspended', $tenant->license->status);
        $this->assertNull($tenant->license->starts_at);
        $this->assertNull($tenant->license->activated_at);
        $this->assertSame('2026-12-31 00:00:00', $tenant->license->expires_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    protected function createDefaultAdminRole(): TenantRole
    {
        TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Owner',
            'description' => 'Default owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);

        return TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Admin',
            'description' => 'Default admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
    }
}
