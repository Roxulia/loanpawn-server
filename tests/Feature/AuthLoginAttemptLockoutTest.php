<?php

namespace Tests\Feature;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthLoginAttemptLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.login_attempts.max_attempts' => 3,
            'auth.login_attempts.attempt_window_seconds' => 60,
            'auth.login_attempts.lockout_seconds' => 300,
        ]);
    }

    public function test_platform_user_unknown_email_returns_not_registered_without_lockout(): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->postJson(route('platform.login.submit'), [
                'email' => 'missing@example.com',
                'password' => 'Password@123',
            ]);

            $response
                ->assertStatus(404)
                ->assertJsonPath('data.code', 'EMAIL_NOT_REGISTERED')
                ->assertJsonPath('message', 'Email is not registered.');
        }
    }

    public function test_platform_user_wrong_password_locks_after_three_attempts(): void
    {
        $user = PlatformUser::query()->create([
            'code' => 'PU0000001',
            'name' => 'Platform User',
            'email' => 'user@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('platform.login.submit'), [
                'email' => $user->email,
                'password' => 'WrongPassword',
            ])->assertStatus(403)
                ->assertJsonPath('data.code', 'INVALID_CREDENTIAL');
        }

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'WrongPassword',
        ])->assertStatus(429)
            ->assertJsonPath('data.code', 'LOGIN_RETRY_LOCKED')
            ->assertJsonPath('data.retry_after', 300);

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'Password@123',
        ])->assertStatus(429)
            ->assertJsonPath('data.code', 'LOGIN_RETRY_LOCKED');
    }

    public function test_platform_user_successful_login_clears_failed_attempts(): void
    {
        $user = PlatformUser::query()->create([
            'code' => 'PU0000002',
            'name' => 'Platform User',
            'email' => 'clear@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('platform.login.submit'), [
                'email' => $user->email,
                'password' => 'WrongPassword',
            ])->assertStatus(403);
        }

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'Password@123',
        ])->assertOk();

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'WrongPassword',
        ])->assertStatus(403)
            ->assertJsonPath('data.code', 'INVALID_CREDENTIAL');
    }

    public function test_pending_verification_platform_user_respects_lockout(): void
    {
        Mail::fake();

        $user = PlatformUser::query()->create([
            'code' => 'PU0000006',
            'name' => 'Pending User',
            'email' => 'pending-lock@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'pending_verification',
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(route('platform.login.submit'), [
                'email' => $user->email,
                'password' => 'WrongPassword',
            ]);
        }

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'Password@123',
        ])->assertStatus(429)
            ->assertJsonPath('data.code', 'LOGIN_RETRY_LOCKED');
    }

    public function test_platform_user_disallowed_status_returns_login_not_allowed(): void
    {
        $user = PlatformUser::query()->create([
            'code' => 'PU0000003',
            'name' => 'Platform User',
            'email' => 'inactive@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'inactive',
        ]);

        $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'Password@123',
        ])->assertStatus(403)
            ->assertJsonPath('data.code', 'LOGIN_NOT_ALLOWED')
            ->assertJsonPath('message', 'You are not allowed to login.');
    }

    public function test_platform_admin_wrong_password_locks_after_three_attempts(): void
    {
        $admin = PlatformAdmin::query()->create([
            'code' => 'PA0000001',
            'username' => 'adminuser',
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('admin.login.submit'), [
                'email' => $admin->email,
                'password' => 'WrongPassword',
            ])->assertStatus(403);
        }

        $this->postJson(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'WrongPassword',
        ])->assertStatus(429)
            ->assertJsonPath('data.code', 'LOGIN_RETRY_LOCKED');
    }

    public function test_tenant_user_wrong_password_lockout_is_scoped_per_tenant(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU0000004',
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);

        $firstTenant = $this->createTenant($platformUser, 'TENANT-A');
        $secondTenant = $this->createTenant($platformUser, 'TENANT-B');
        $this->createTenantUser($firstTenant, 'shared@example.com', 'Password@123');
        $this->createTenantUser($secondTenant, 'shared@example.com', 'Password@123');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->postJson('/api/tenant/login/public-spa', [
                'tenant_code' => $firstTenant->tenant_code,
                'email' => 'shared@example.com',
                'password' => 'WrongPassword',
            ]);

            $attempt < 2
                ? $response->assertStatus(403)
                : $response->assertStatus(429)->assertJsonPath('data.code', 'LOGIN_RETRY_LOCKED');
        }

        $this->postJson('/api/tenant/login/public-spa', [
            'tenant_code' => $secondTenant->tenant_code,
            'email' => 'shared@example.com',
            'password' => 'Password@123',
        ])->assertOk();
    }

    public function test_tenant_user_disallowed_status_returns_login_not_allowed(): void
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU0000005',
            'name' => 'Owner',
            'email' => 'tenant-owner@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);
        $tenant = $this->createTenant($platformUser, 'TENANT-C');
        $tenantUser = $this->createTenantUser($tenant, 'blocked@example.com', 'Password@123', 'suspended');

        $this->postJson('/api/tenant/login/public-spa', [
            'tenant_code' => $tenant->tenant_code,
            'email' => $tenantUser->email,
            'password' => 'Password@123',
        ])->assertStatus(403)
            ->assertJsonPath('data.code', 'LOGIN_NOT_ALLOWED');
    }

    private function createTenant(PlatformUser $platformUser, string $tenantCode): Tenant
    {
        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => $tenantCode,
            'tenant_code' => $tenantCode,
            'status' => 'active',
        ]);
    }

    private function createTenantUser(
        Tenant $tenant,
        string $email,
        string $password,
        string $status = 'inactive',
    ): TenantUser {
        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->create([
                'tenant_id' => $tenant->id,
                'code' => 'TU'.str_pad((string) $tenant->id, 8, '0', STR_PAD_LEFT).substr(sha1($email), 0, 4),
                'username' => 'u'.str_pad((string) $tenant->id, 7, '0', STR_PAD_LEFT),
                'name' => 'Tenant User',
                'nrc' => 'NRC-'.$tenant->id,
                'email' => $email,
                'phone' => '09'.str_pad((string) $tenant->id, 9, '0', STR_PAD_LEFT),
                'password' => Hash::make($password),
                'status' => $status,
            ]);
    }
}
