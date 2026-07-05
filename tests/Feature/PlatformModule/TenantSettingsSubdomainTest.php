<?php

namespace Tests\Feature\PlatformModule;

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSettingsSubdomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_tenant_with_subdomain_feature_sees_subdomain_input_and_counter(): void
    {
        $tenant = $this->createTenantWithLicense('basic', 'visible-subdomain');

        $this->actingAs($tenant->owner, 'platformuser')
            ->get(route('platform.tenants.edit', $tenant->id))
            ->assertOk()
            ->assertSee('name="subdomain"', false)
            ->assertSee('maxlength="25"', false)
            ->assertSee('data-character-counter="subdomain-character-count"', false)
            ->assertSee(strlen($tenant->subdomain).'/25');
    }

    public function test_tenant_without_subdomain_feature_does_not_see_subdomain_input(): void
    {
        $this->disableSubdomainFeatureForPlan('trial');
        $tenant = $this->createTenantWithLicense('trial', 'hidden-subdomain');

        $this->actingAs($tenant->owner, 'platformuser')
            ->get(route('platform.tenants.edit', $tenant->id))
            ->assertOk()
            ->assertDontSee('name="subdomain"', false)
            ->assertDontSee('subdomain-character-count');
    }

    public function test_tenant_with_feature_can_update_unused_subdomain_up_to_twenty_five_characters(): void
    {
        $tenant = $this->createTenantWithLicense('basic', 'old-subdomain');
        $newSubdomain = 'abcdefghijklmnopqrstuvwxy';

        $this->actingAs($tenant->owner, 'platformuser')
            ->put(route('platform.tenants.update', $tenant->id), $this->settingsPayload($tenant, [
                'subdomain' => $newSubdomain,
            ]))
            ->assertRedirect(route('platform.tenants.edit', $tenant->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => $newSubdomain,
        ]);
    }

    public function test_duplicate_subdomain_submission_is_rejected(): void
    {
        $tenant = $this->createTenantWithLicense('basic', 'owner-subdomain');
        $otherTenant = $this->createTenantWithLicense('basic', 'taken-subdomain', 'other-owner@example.com');

        $this->actingAs($tenant->owner, 'platformuser')
            ->from(route('platform.tenants.edit', $tenant->id))
            ->put(route('platform.tenants.update', $tenant->id), $this->settingsPayload($tenant, [
                'subdomain' => $otherTenant->subdomain,
            ]))
            ->assertRedirect(route('platform.tenants.edit', $tenant->id))
            ->assertSessionHasErrors('subdomain');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => 'owner-subdomain',
        ]);
    }

    public function test_subdomain_longer_than_twenty_five_characters_is_rejected(): void
    {
        $tenant = $this->createTenantWithLicense('basic', 'short-subdomain');

        $this->actingAs($tenant->owner, 'platformuser')
            ->from(route('platform.tenants.edit', $tenant->id))
            ->put(route('platform.tenants.update', $tenant->id), $this->settingsPayload($tenant, [
                'subdomain' => 'abcdefghijklmnopqrstuvwxyz',
            ]))
            ->assertRedirect(route('platform.tenants.edit', $tenant->id))
            ->assertSessionHasErrors('subdomain');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => 'short-subdomain',
        ]);
    }

    public function test_tenant_without_feature_cannot_update_subdomain_with_crafted_payload(): void
    {
        $this->disableSubdomainFeatureForPlan('trial');
        $tenant = $this->createTenantWithLicense('trial', 'locked-subdomain');

        $this->actingAs($tenant->owner, 'platformuser')
            ->from(route('platform.tenants.edit', $tenant->id))
            ->put(route('platform.tenants.update', $tenant->id), $this->settingsPayload($tenant, [
                'subdomain' => 'crafted-subdomain',
            ]))
            ->assertRedirect(route('platform.tenants.edit', $tenant->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => 'locked-subdomain',
        ]);
    }

    public function test_tenant_without_feature_can_submit_other_settings_without_subdomain(): void
    {
        $this->disableSubdomainFeatureForPlan('trial');
        $tenant = $this->createTenantWithLicense('trial', 'unchanged-subdomain');

        $this->actingAs($tenant->owner, 'platformuser')
            ->put(route('platform.tenants.update', $tenant->id), $this->settingsPayload($tenant, [
                'name' => 'Renamed Tenant',
            ], includeSubdomain: false))
            ->assertRedirect(route('platform.tenants.edit', $tenant->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Renamed Tenant',
            'subdomain' => 'unchanged-subdomain',
        ]);
    }

    protected function settingsPayload(Tenant $tenant, array $overrides = [], bool $includeSubdomain = true): array
    {
        $payload = [
            'update_key' => (int) $tenant->update_key,
            'name' => $tenant->name,
            'address' => 'No. 1 Main Road',
            'phone' => '09123456789',
            'city' => 'Yangon',
            'country' => 'Myanmar',
        ];

        if ($includeSubdomain) {
            $payload['subdomain'] = $tenant->subdomain;
        }

        return array_merge($payload, $overrides);
    }

    protected function disableSubdomainFeatureForPlan(string $planType): void
    {
        $feature = Feature::query()->where('code', 'subdomain_available')->firstOrFail();
        $package = Package::query()->where('code', $planType)->firstOrFail();

        PackageFeature::query()
            ->where('feature_id', $feature->id)
            ->where('package_id', $package->id)
            ->update(['is_enabled' => false]);
    }

    protected function createTenantWithLicense(
        string $planType,
        string $subdomain,
        ?string $email = null,
    ): Tenant {
        $tenantCode = str_replace('-', '', $subdomain);

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Demo User',
            'email' => $email ?? $subdomain.'@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => ucfirst($planType).' Tenant',
            'tenant_code' => substr($tenantCode, 0, 30),
            'subdomain' => $subdomain,
            'status' => 'active',
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => strtoupper(substr(str_pad($tenantCode, 16, 'X'), 0, 16)),
            'plan_type' => $planType,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        return $tenant->refresh()->load('owner', 'license');
    }
}
