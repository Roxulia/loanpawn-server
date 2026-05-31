<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\TenantAuditLogService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantAuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_and_gets_tenant_audit_logs_between_dates(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant);

        app(TenantAuditLogService::class)->log(
            action: 'tenant_customer.created',
            targetType: 'TenantCustomer',
            targetId: 10,
            meta: ['name' => 'Audit Customer'],
        );

        $logs = app(TenantAuditLogService::class)->getLog(
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
        );

        $this->assertCount(1, $logs->items);
        $this->assertSame('tenant_customer.created', $logs->items[0]->action);
        $this->assertSame($tenantUser->id, $logs->items[0]->actorUserId);
        $this->assertSame(['name' => 'Audit Customer'], $logs->items[0]->meta);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_customer.created',
            'target_type' => 'TenantCustomer',
            'target_id' => 10,
        ]);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Audit Owner',
            'email' => 'audit-owner@example.com',
            'phone' => '09111112222',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Audit Tenant',
            'tenant_code' => 'audit-tenant',
            'subdomain' => 'audit-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Audit Role',
            'description' => 'Audit role',
            'is_default' => false,
            'permissions' => ['access_all'],
        ]);

        $tenantUser = TenantUser::query()->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'AUD001',
            'name' => 'Audit User',
            'nrc' => '12/PaTaNa(N)000222',
            'email' => 'audit-user@example.com',
            'phone' => '0955555222',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }
}
