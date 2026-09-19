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

class TenantSettingsBootstrapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PackageSeeder::class);
    }

    public function test_tenant_bootstrap_omits_sections_without_permission(): void
    {
        [$tenant] = $this->tenantUserContext(['manage_tenant_contact'], 'contact');

        $this->tenantGetJson($tenant, '/api/tenant/settings/tenant')
            ->assertOk()
            ->assertJsonStructure(['data' => ['contact']])
            ->assertJsonMissingPath('data.branding')
            ->assertJsonMissingPath('data.tenant_setting')
            ->assertJsonMissingPath('data.timezone');
    }

    public function test_default_data_bootstrap_only_returns_permitted_lists(): void
    {
        [$tenant] = $this->tenantUserContext(['list_interest_type'], 'interest');

        $this->tenantGetJson($tenant, '/api/tenant/settings/default-data')
            ->assertOk()
            ->assertJsonStructure(['data' => ['interest_types' => ['items', 'current_page', 'last_page', 'per_page', 'total']]])
            ->assertJsonPath('data.interest_types.per_page', 5)
            ->assertJsonMissingPath('data.expense_types')
            ->assertJsonMissingPath('data.material_types')
            ->assertJsonMissingPath('data.item_category_types');
    }

    public function test_bootstrap_rejects_users_without_section_permission(): void
    {
        [$tenant] = $this->tenantUserContext([], 'denied');

        $this->tenantGetJson($tenant, '/api/tenant/settings/default-data')->assertForbidden();
    }

    public function test_debt_payment_policy_defaults_off_and_can_be_enabled_by_authorized_user(): void
    {
        [$tenant] = $this->tenantUserContext(['manage_debt_settings'], 'debt-policy');

        $this->tenantGetJson($tenant, '/api/tenant/settings/tenant')
            ->assertOk()
            ->assertJsonPath('data.debt_payment_policy.allow_partial_payments', false)
            ->assertJsonPath('data.debt_payment_policy.update_key', 0);

        $this->withHeader('X-Tenant-Code', $tenant->tenant_code)
            ->putJson('/api/tenant/settings/debt-payment-policy', [
                'allow_partial_payments' => true,
                'update_key' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.allow_partial_payments', true)
            ->assertJsonPath('data.update_key', 1);

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'key' => 'allow_partial_debt_payments',
            'value' => 'true',
            'update_key' => 1,
        ]);
    }

    private function tenantGetJson(Tenant $tenant, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->getJson($uri);
    }

    private function tenantUserContext(array $permissions, string $suffix): array
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner '.$suffix,
            'email' => "settings-owner-{$suffix}@example.com",
            'phone' => '0999'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Settings '.$suffix,
            'tenant_code' => 'settings-'.$suffix,
            'subdomain' => 'settings-'.$suffix,
            'status' => 'active',
        ]);
        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'SETTING'.strtoupper($suffix),
            'plan_type' => 'basic',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);
        $role = TenantRole::query()->create([
            'name' => 'Settings '.$suffix,
            'description' => 'Settings test role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);
        $user = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'SET'.strtoupper($suffix),
            'name' => 'Settings User',
            'nrc' => '12/PaTaNa(N)000001',
            'email' => "settings-user-{$suffix}@example.com",
            'phone' => '0955'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Sanctum::actingAs($user, [], 'tenantuser');

        return [$tenant, $user];
    }
}
