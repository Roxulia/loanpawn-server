<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_it_lists_role_options(): void
    {
        [$tenant] = $this->tenantUserContext(['create_user']);

        $adminRole = TenantRole::query()->create([
            'name' => 'Admin',
            'description' => 'Admin role',
            'is_default' => true,
            'permissions' => ['access_all'],
        ]);
        $staffRole = TenantRole::query()->create([
            'name' => 'Staff',
            'description' => 'Staff role',
            'is_default' => true,
            'permissions' => ['list_user'],
        ]);

        TenantRole::query()->create([
            'name' => 'Deleted Role',
            'description' => 'Deleted role',
            'is_default' => false,
            'is_deleted' => true,
        ]);

        $response = $this->tenantGetJson($tenant, '/api/tenant/user-roles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.role_id', $adminRole->id)
            ->assertJsonPath('data.0.role_name', 'Admin')
            ->assertJsonPath('data.1.role_id', $staffRole->id)
            ->assertJsonPath('data.1.role_name', 'Staff')
            ->assertJsonMissing(['role_name' => 'Deleted Role']);
    }

    public function test_it_requires_authentication(): void
    {
        $tenant = $this->createTenant('guest');

        $this->tenantGetJson($tenant, '/api/tenant/user-roles')
            ->assertUnauthorized();
    }

    public function test_it_rejects_user_without_required_permission(): void
    {
        [$tenant] = $this->tenantUserContext([]);

        $this->tenantGetJson($tenant, '/api/tenant/user-roles')
            ->assertForbidden();
    }

    private function tenantGetJson(Tenant $tenant, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->getJson($uri);
    }

    private function tenantUserContext(array $permissions, string $suffix = 'roles'): array
    {
        $tenant = $this->createTenant($suffix);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'ROLETEST'.strtoupper($suffix),
            'plan_type' => 'basic',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        $role = TenantRole::query()->create([
            'name' => 'Zulu Request Role '.$suffix,
            'description' => 'Request role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'ROLE'.strtoupper($suffix),
            'name' => 'Role User '.$suffix,
            'nrc' => '12/PaTaNa(N)000001',
            'email' => "role-user-{$suffix}@example.com",
            'phone' => '0955'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        return [$tenant, $tenantUser];
    }

    private function createTenant(string $suffix): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User '.$suffix,
            'email' => "owner-{$suffix}@example.com",
            'phone' => '0999'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant '.$suffix,
            'tenant_code' => 'demo-tenant-'.$suffix,
            'subdomain' => 'demo-subdomain-'.$suffix,
            'status' => 'active',
        ]);
    }
}
