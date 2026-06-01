<?php

namespace Tests\Feature\PawnModule;

use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollateralItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_tenant_user_can_manage_normal_items_via_api(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext();

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $created = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/collateral-items', [
                'type' => 'Normal',
                'name' => 'Laptop',
                'description' => 'Gaming laptop',
                'brand_name' => 'Dell',
                'estimated_value' => 1200,
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Laptop')
            ->assertJsonPath('data.type', 'Normal');

        $itemId = $created->json('data.id');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson("/api/tenant/collateral-items/{$itemId}", [
                'item_status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.item_status', 'inactive');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->deleteJson("/api/tenant/collateral-items/{$itemId}")
            ->assertOk();
    }

    public function test_tenant_user_can_manage_jewellery_items_and_view_merged_collateral_list(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext();
        $materialType = MaterialType::query()->create([
            'tenant_id' => null,
            'code' => 'gold',
            'name' => 'Gold',
            'is_default' => true,
        ]);

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/collateral-items', [
                'type' => 'Normal',
                'name' => 'Camera',
                'description' => 'Sony Mirrorless',
            ])
            ->assertCreated();

        $created = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->postJson('/api/tenant/collateral-items', [
                'type' => 'Jewellery',
                'name' => 'Ring',
                'material_type_id' => $materialType->id,
                'kyat' => 1,
                'pal' => 2,
                'yway' => 3,
                'contains_gemstones' => true,
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Ring');

        $itemId = $created->json('data.id');

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson("/api/tenant/collateral-items/{$itemId}", [
                'pal' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.pal', '4.00');

        $response = $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->getJson('/api/tenant/collateral-items')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $itemTypes = array_column($response->json('data.items'), 'item_type');

        $this->assertContains('Normal', $itemTypes);
        $this->assertContains('Jewellery', $itemTypes);
    }

    public function test_tenant_context_can_be_resolved_from_auth_cookie_without_tenant_header(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext();

        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        $this->withCookie('tenant_auth_token', json_encode([
            'tenantId' => $tenant->id,
            'tenantCode' => $tenant->tenant_code,
        ], JSON_THROW_ON_ERROR))
            ->getJson('/api/tenant/collateral-items')
            ->assertOk();
    }

    protected function tenantUserContext(): array
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'phone' => '09999999999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant',
            'tenant_code' => 'demo-tenant',
            'subdomain' => 'demo-subdomain',
            'status' => 'active',
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'COLLATERALTEST01',
            'plan_type' => 'basic',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'User',
            'description' => 'User role',
            'is_default' => false,
            'permissions' => config('tenant_permissions.roles.User.permissions'),
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'ADMIN001',
            'name' => 'Tenant Admin',
            'nrc' => '12/PaTaNa(N)000001',
            'email' => 'tenant-admin@example.com',
            'phone' => '0955555555',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        return [$tenant, $tenantUser];
    }
}
