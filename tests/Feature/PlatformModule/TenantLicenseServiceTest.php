<?php

namespace Tests\Feature\PlatformModule;

use App\Jobs\CheckExpireTenantLicenseJob;
use App\Jobs\ResetTenantLicenseMonthlySlipCountJob;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantLicenseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_expire_updates_expired_licenses_only(): void
    {
        Carbon::setTestNow('2026-04-17 10:00:00');

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'License Owner',
            'email' => 'license-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $expiredTenant = $this->createTenant($platformUser, 'expired-tenant');
        $activeTenant = $this->createTenant($platformUser, 'active-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $expiredTenant->id,
            'license_key' => 'EXPIREDLICENSE001',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $activeTenant->id,
            'license_key' => 'ACTIVELICENSE0002',
            'plan_type' => 'premium',
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);

        $updatedCount = app(TenantLicenseService::class)->checkExpire();

        $this->assertSame(1, $updatedCount);

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $expiredTenant->id,
            'status' => 'expired',
        ]);

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $activeTenant->id,
            'status' => 'active',
        ]);

        Carbon::setTestNow();
    }

    public function test_it_validates_only_active_non_expired_license_keys(): void
    {
        Carbon::setTestNow('2026-04-17 10:00:00');

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Validation Owner',
            'email' => 'validation-owner@example.com',
            'phone' => '09111119999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $activeTenant = $this->createTenant($platformUser, 'valid-license-tenant');
        $expiredTenant = $this->createTenant($platformUser, 'expired-license-tenant');
        $inactiveTenant = $this->createTenant($platformUser, 'inactive-license-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $activeTenant->id,
            'license_key' => 'VALIDLICENSE0001',
            'plan_type' => 'premium',
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $expiredTenant->id,
            'license_key' => 'EXPIREDLICENSE02',
            'plan_type' => 'premium',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $inactiveTenant->id,
            'license_key' => 'INACTIVELICENSE1',
            'plan_type' => 'premium',
            'status' => 'suspended',
            'expires_at' => now()->addDay(),
        ]);

        $valid = app(TenantLicenseService::class)->validateLicenseKey('VALIDLICENSE0001');
        $expired = app(TenantLicenseService::class)->validateLicenseKey('EXPIREDLICENSE02');
        $inactive = app(TenantLicenseService::class)->validateLicenseKey('INACTIVELICENSE1');
        $missing = app(TenantLicenseService::class)->validateLicenseKey('MISSINGLICENSE01');

        $this->assertTrue($valid->valid);
        $this->assertSame('valid-license-tenant', $valid->tenantCode);
        $this->assertFalse($expired->valid);
        $this->assertFalse($inactive->valid);
        $this->assertFalse($missing->valid);

        Carbon::setTestNow();
    }

    public function test_public_license_validation_api_returns_tenant_bootstrap_identity(): void
    {
        Carbon::setTestNow('2026-04-17 10:00:00');

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'API License Owner',
            'email' => 'api-license-owner@example.com',
            'phone' => '09111118888',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'api-license-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'APILICENSE000001',
            'plan_type' => 'premium',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $this->postJson('/api/license/validate', [
            'license_key' => 'APILICENSE000001',
        ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.tenant_code', 'api-license-tenant')
            ->assertJsonPath('data.license.status', 'active');

        $this->postJson('/api/license/validate', [
            'license_key' => 'MISSINGLICENSE01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.valid', false);

        Carbon::setTestNow();
    }

    public function test_job_calls_license_expire_check(): void
    {
        Carbon::setTestNow('2026-04-17 10:00:00');

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Job Owner',
            'email' => 'job-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $tenant = $this->createTenant($platformUser, 'job-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'JOBEXPIREDLICENSE',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->subHour(),
        ]);

        app(CheckExpireTenantLicenseJob::class)->handle(app(TenantLicenseService::class));

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'status' => 'expired',
        ]);

        Carbon::setTestNow();
    }

    public function test_tenant_license_usage_counters_default_to_zero(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Counter Owner',
            'email' => 'counter-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'counter-tenant');

        $license = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'COUNTERLICENSE01',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertSame(0, $license->refresh()->current_month_slip_count);
        $this->assertSame(0, $license->current_staff_count);
    }

    public function test_monthly_slip_count_reset_updates_all_licenses_without_staff_count(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Reset Owner',
            'email' => 'reset-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $activeTenant = $this->createTenant($platformUser, 'reset-active-tenant');
        $expiredTenant = $this->createTenant($platformUser, 'reset-expired-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $activeTenant->id,
            'license_key' => 'RESETLICENSE0001',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'current_month_slip_count' => 12,
            'current_staff_count' => 3,
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $expiredTenant->id,
            'license_key' => 'RESETLICENSE0002',
            'plan_type' => 'premium',
            'status' => 'expired',
            'expires_at' => now()->subMonth(),
            'current_month_slip_count' => 25,
            'current_staff_count' => 7,
        ]);

        $updated = app(TenantLicenseService::class)->resetCurrentMonthSlipCounts();

        $this->assertSame(2, $updated);
        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $activeTenant->id,
            'current_month_slip_count' => 0,
            'current_staff_count' => 3,
        ]);
        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $expiredTenant->id,
            'current_month_slip_count' => 0,
            'current_staff_count' => 7,
        ]);
    }

    public function test_monthly_reset_job_calls_license_reset(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Reset Job Owner',
            'email' => 'reset-job-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'reset-job-tenant');

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'RESETJOBLICENSE1',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'current_month_slip_count' => 9,
            'current_staff_count' => 2,
        ]);

        app(ResetTenantLicenseMonthlySlipCountJob::class)->handle(app(TenantLicenseService::class));

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'current_month_slip_count' => 0,
            'current_staff_count' => 2,
        ]);
    }

    public function test_license_limit_check_maps_counters_to_package_limits(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Limit Owner',
            'email' => 'limit-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'limit-tenant');
        Package::query()->create([
            'code' => 'basic',
            'name' => 'Basic',
            'price' => 1000,
            'max_slip_per_month' => 2,
            'max_staff_count' => 3,
            'is_active' => false,
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'LIMITLICENSE001',
            'plan_type' => 'basic',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'current_month_slip_count' => 1,
            'current_staff_count' => 3,
        ]);
        app(TenantContext::class)->set($tenant);

        $service = app(TenantLicenseService::class);

        $this->assertFalse($service->checkIfLimitReach('current_month_slip_count'));
        $this->assertTrue($service->checkIfLimitReach('current_staff_count'));
    }

    public function test_license_limit_check_allows_unlimited_package_values(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Unlimited Owner',
            'email' => 'unlimited-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'unlimited-tenant');
        Package::query()->create([
            'code' => 'premium',
            'name' => 'Premium',
            'price' => 1000,
            'max_slip_per_month' => null,
            'max_staff_count' => null,
            'is_active' => true,
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'UNLIMITEDLIC001',
            'plan_type' => 'premium',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'current_month_slip_count' => 1000,
            'current_staff_count' => 100,
        ]);
        app(TenantContext::class)->set($tenant);

        $service = app(TenantLicenseService::class);

        $this->assertFalse($service->checkIfLimitReach('current_month_slip_count'));
        $this->assertFalse($service->checkIfLimitReach('current_staff_count'));
    }

    protected function createTenant(PlatformUser $platformUser, string $code): Tenant
    {
        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => ucfirst($code),
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
    }
}
