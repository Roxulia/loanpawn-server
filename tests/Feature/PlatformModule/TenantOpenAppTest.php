<?php

namespace Tests\Feature\PlatformModule;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantOpenAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_app_returns_json_redirect_for_active_license(): void
    {
        Carbon::setTestNow('2026-06-13 10:00:00');

        $platformUser = $this->createPlatformUser('active-open-app-owner@example.com');
        $tenant = $this->createTenant($platformUser, 'active-open-app-tenant');
        $this->createTenantUser($tenant, $platformUser->email);
        $this->createLicense($tenant, 'active', now()->addDay());

        $response = $this
            ->actingAs($platformUser, 'platformuser')
            ->postJson(route('platform.tenants.open-app', $tenant));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.redirect_url', fn (string $url): bool => str_contains($url, 'tenantCode=active-open-app-tenant')
                && str_contains($url, 'token='));

        $this->assertDatabaseCount('tenant_sso_tokens', 1);

        Carbon::setTestNow();
    }

    public function test_open_app_returns_json_error_when_license_date_is_expired(): void
    {
        Carbon::setTestNow('2026-06-13 10:00:00');

        $platformUser = $this->createPlatformUser('date-expired-open-app-owner@example.com');
        $tenant = $this->createTenant($platformUser, 'date-expired-open-app-tenant');
        $this->createLicense($tenant, 'active', now()->subMinute());

        $response = $this
            ->actingAs($platformUser, 'platformuser')
            ->postJson(route('platform.tenants.open-app', $tenant));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tenant is expired');

        $this->assertDatabaseCount('tenant_sso_tokens', 0);

        Carbon::setTestNow();
    }

    public function test_open_app_returns_json_error_when_license_status_is_expired(): void
    {
        Carbon::setTestNow('2026-06-13 10:00:00');

        $platformUser = $this->createPlatformUser('status-expired-open-app-owner@example.com');
        $tenant = $this->createTenant($platformUser, 'status-expired-open-app-tenant');
        $this->createLicense($tenant, 'expired', now()->addDay());

        $response = $this
            ->actingAs($platformUser, 'platformuser')
            ->postJson(route('platform.tenants.open-app', $tenant));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tenant is expired');

        $this->assertDatabaseCount('tenant_sso_tokens', 0);

        Carbon::setTestNow();
    }

    private function createPlatformUser(string $email): PlatformUser
    {
        return PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Open App Owner',
            'email' => $email,
            'password' => 'Password@123',
            'status' => 'active',
        ]);
    }

    private function createTenant(PlatformUser $platformUser, string $code): Tenant
    {
        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
    }

    private function createTenantUser(Tenant $tenant, string $email): TenantUser
    {
        return TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'username' => 'owner'.random_int(100, 999),
            'name' => 'Tenant Owner',
            'nrc' => 'NRC'.random_int(100000, 999999),
            'email' => $email,
            'password' => 'Password@123',
            'status' => 'active',
        ]);
    }

    private function createLicense(Tenant $tenant, string $status, Carbon $expiresAt): TenantLicense
    {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'OPENAPP'.str_pad((string) random_int(0, 99999999), 9, '0', STR_PAD_LEFT),
            'plan_type' => 'basic',
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }
}
