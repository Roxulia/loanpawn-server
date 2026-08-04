<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\AuthService;
use App\Support\TenantUserActivityCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantUserActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_expires_only_active_users_without_recent_activity(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 12:00:00');
        config(['session.lifetime' => 120]);

        $firstTenant = $this->createTenant('first');
        $secondTenant = $this->createTenant('second');

        $stale = $this->createTenantUser($firstTenant, 'stale@example.com', 'active');
        $neverTracked = $this->createTenantUser($secondTenant, 'null@example.com', 'active');
        $recent = $this->createTenantUser($firstTenant, 'recent@example.com', 'active');
        $inactive = $this->createTenantUser($firstTenant, 'inactive@example.com', 'inactive');
        $suspended = $this->createTenantUser($firstTenant, 'suspended@example.com', 'suspended');
        $deleted = $this->createTenantUser($firstTenant, 'deleted@example.com', 'active', true);
        app(TenantUserActivityCache::class)->remember($recent);

        $count = app(AuthService::class)->expireInactiveTenantUsers();

        $this->assertSame(2, $count);
        $this->assertSame('inactive', $stale->refresh()->status);
        $this->assertSame('inactive', $neverTracked->refresh()->status);
        $this->assertSame('active', $recent->refresh()->status);
        $this->assertSame('inactive', $inactive->refresh()->status);
        $this->assertSame('suspended', $suspended->refresh()->status);
        $this->assertSame('active', $deleted->refresh()->status);
    }

    public function test_authenticated_request_records_activity_and_reactivates_inactive_user(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 12:00:00');
        $tenant = $this->createTenant('request');
        $user = $this->createTenantUser($tenant, 'request@example.com', 'inactive');
        Sanctum::actingAs($user, [], 'tenantuser');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson('/api/tenant/me')
            ->assertOk();

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertTrue(app(TenantUserActivityCache::class)->has($user));
    }

    public function test_active_user_requests_refresh_cache_without_updating_tenant_user_row(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 12:00:00');
        $tenant = $this->createTenant('throttle');
        $user = $this->createTenantUser($tenant, 'cache@example.com', 'active');
        $service = app(AuthService::class);
        $originalUpdatedAt = $user->updated_at;

        $service->recordAuthenticatedActivity($user);
        $service->recordAuthenticatedActivity($user);

        $this->assertTrue(app(TenantUserActivityCache::class)->has($user));
        $this->assertTrue($user->refresh()->updated_at->equalTo($originalUpdatedAt));
    }

    protected function createTenant(string $suffix): Tenant
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner '.$suffix,
            'email' => "owner-activity-{$suffix}@example.com",
            'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Activity Tenant '.$suffix,
            'tenant_code' => 'activity-'.$suffix,
            'subdomain' => 'activity-'.$suffix,
            'status' => 'active',
        ]);
    }

    protected function createTenantUser(
        Tenant $tenant,
        string $email,
        string $status,
        bool $isDeleted = false,
    ): TenantUser {
        $role = TenantRole::query()->create([
            'name' => 'Activity Role '.md5($email),
            'description' => 'Activity test role',
            'is_default' => false,
            'permissions' => [],
        ]);

        return TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => substr(md5($email), 0, 8),
            'name' => 'Activity User',
            'nrc' => substr(md5($email.'nrc'), 0, 20),
            'email' => $email,
            'phone' => '09'.substr((string) abs(crc32($email)), 0, 8),
            'password' => 'secret123',
            'status' => $status,
            'is_deleted' => $isDeleted,
        ]);
    }
}
