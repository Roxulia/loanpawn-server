<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\TenantRequestCreate;
use App\Exceptions\InvalidTenantRequest;
use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Models\PlatformModule\TenantRequest;
use App\Services\PlatformModule\PackageService;
use App\Services\PlatformModule\TenantRequestService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PackageFlagAndPlanTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_master_data_feature_is_enabled_for_paid_plans_only(): void
    {
        $service = app(PackageService::class);

        $this->assertFalse($service->planHasFeature('trial', 'master_data_management'));
        $this->assertTrue($service->planHasFeature('basic', 'master_data_management'));
        $this->assertTrue($service->planHasFeature('premium', 'master_data_management'));
    }

    public function test_accounting_type_management_is_enabled_for_budgeting_plans_only(): void
    {
        $service = app(PackageService::class);

        $this->assertFalse($service->planHasFeature('trial', 'accounting_type_management'));
        $this->assertFalse($service->planHasFeature('basic', 'accounting_type_management'));
        $this->assertFalse($service->planHasFeature('premium', 'accounting_type_management'));
        $this->assertTrue($service->planHasFeature('budgeting-trial', 'accounting_type_management'));
        $this->assertTrue($service->planHasFeature('budgeting-basic', 'accounting_type_management'));
        $this->assertTrue($service->planHasFeature('budgeting-premium', 'accounting_type_management'));
    }

    public function test_existing_license_keeps_enabled_features_when_plan_sales_are_disabled(): void
    {
        Package::query()->where('code', 'basic')->update(['is_active' => false]);
        $service = app(PackageService::class);

        $this->assertTrue($service->planHasFeature('basic', 'customer_management'));
        $this->assertNull($service->activePaidPackagesExcept()->firstWhere('code', 'basic'));

        Feature::query()->where('code', 'customer_management')->update(['is_active' => false]);

        $this->assertFalse($service->planHasFeature('basic', 'customer_management'));
    }

    public function test_package_seeder_persists_usage_limits(): void
    {
        $this->assertDatabaseHas('packages', [
            'code' => 'trial',
            'max_slip_per_month' => 30,
            'max_staff_count' => 2,
            'max_account_count' => 1,
            'max_currency_type_count' => 3,
            'max_exchange_pair_count' => 2,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'basic',
            'max_slip_per_month' => 300,
            'max_staff_count' => 5,
            'max_account_count' => 5,
            'max_currency_type_count' => 10,
            'max_exchange_pair_count' => 10,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'premium',
            'max_slip_per_month' => null,
            'max_staff_count' => null,
            'max_account_count' => null,
            'max_currency_type_count' => null,
            'max_exchange_pair_count' => null,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'budgeting-trial',
            'max_account_count' => 1,
            'max_currency_type_count' => 3,
            'max_exchange_pair_count' => 2,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'budgeting-basic',
            'max_account_count' => 5,
            'max_currency_type_count' => 10,
            'max_exchange_pair_count' => 10,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'budgeting-premium',
            'max_account_count' => null,
            'max_currency_type_count' => null,
            'max_exchange_pair_count' => null,
        ]);
    }

    public function test_premium_to_basic_approval_is_activated_at_license_expiry(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        [$license, $tenantRequest, $admin] = $this->downgradeFixture();
        $service = app(TenantLicenseService::class);

        $service->applyApprovedTenantRequest($tenantRequest, $admin->id);

        $this->assertSame('premium', $license->refresh()->plan_type);
        $this->assertDatabaseHas('tenant_license_plan_transitions', [
            'tenant_license_id' => $license->id,
            'tenant_request_id' => $tenantRequest->id,
            'from_plan_type' => 'premium',
            'to_plan_type' => 'basic',
            'status' => 'scheduled',
        ]);

        Carbon::setTestNow('2026-07-01 10:00:01');
        $service->checkExpire();

        $this->assertSame('basic', $license->refresh()->plan_type);
        $this->assertSame('active', $license->status);
        $this->assertSame('2026-10-01 10:00:00', $license->expires_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('tenant_license_plan_transitions', [
            'tenant_license_id' => $license->id,
            'status' => 'activated',
        ]);

        Carbon::setTestNow();
    }

    public function test_admin_can_update_feature_plan_and_mapping_flags(): void
    {
        $admin = $this->flagAdmin();
        $feature = Feature::query()->where('code', 'customer_management')->firstOrFail();
        $package = Package::query()->where('code', 'basic')->firstOrFail();
        $mapping = PackageFeature::query()
            ->where('package_id', $package->id)
            ->where('feature_id', $feature->id)
            ->firstOrFail();

        $this->actingAs($admin, 'platformadmin')
            ->put(route('admin.package-flags.update'), [
                'features' => [$feature->id => 0],
                'packages' => [$package->id => 0],
                'mappings' => [$mapping->id => 0],
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $this->assertDatabaseHas('features', ['id' => $feature->id, 'is_active' => false]);
        $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => false]);
        $this->assertDatabaseHas('package_features', ['id' => $mapping->id, 'is_enabled' => false]);
    }

    public function test_admin_can_update_plan_flags_separately(): void
    {
        $admin = $this->flagAdmin();
        $package = Package::query()->where('code', 'basic')->firstOrFail();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.package-flags.plans.update'), [
                'packages' => [$package->id => 0],
                'max_slip_per_month' => [$package->id => 250],
                'max_staff_count' => [$package->id => 4],
                'max_account_count' => [$package->id => 8],
                'max_currency_type_count' => [$package->id => 12],
                'max_exchange_pair_count' => [$package->id => 15],
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'is_active' => false,
            'max_slip_per_month' => 250,
            'max_staff_count' => 4,
            'max_account_count' => 8,
            'max_currency_type_count' => 12,
            'max_exchange_pair_count' => 15,
        ]);
    }

    public function test_admin_can_store_blank_plan_limits_as_unlimited(): void
    {
        $admin = $this->flagAdmin();
        $package = Package::query()->where('code', 'basic')->firstOrFail();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.package-flags.plans.update'), [
                'packages' => [$package->id => 1],
                'max_slip_per_month' => [$package->id => null],
                'max_staff_count' => [$package->id => ''],
                'max_account_count' => [$package->id => null],
                'max_currency_type_count' => [$package->id => ''],
                'max_exchange_pair_count' => [$package->id => null],
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'is_active' => true,
            'max_slip_per_month' => null,
            'max_staff_count' => null,
            'max_account_count' => null,
            'max_currency_type_count' => null,
            'max_exchange_pair_count' => null,
        ]);
    }

    public function test_admin_can_create_feature_with_disabled_plan_assignments(): void
    {
        $admin = $this->flagAdmin();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.package-flags.features.store'), [
                'name' => 'Inventory reports',
                'code' => 'inventory_reports',
                'description' => 'View inventory reporting screens.',
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $feature = Feature::query()->where('code', 'inventory_reports')->firstOrFail();

        $this->assertTrue($feature->is_active);
        Package::query()->each(function (Package $package) use ($feature): void {
            $this->assertDatabaseHas('package_features', [
                'package_id' => $package->id,
                'feature_id' => $feature->id,
                'is_enabled' => false,
            ]);
        });
    }

    public function test_admin_can_update_feature_flags_separately(): void
    {
        $admin = $this->flagAdmin();
        $feature = Feature::query()->where('code', 'customer_management')->firstOrFail();

        $this->actingAs($admin, 'platformadmin')
            ->put(route('admin.package-flags.features.update'), [
                'features' => [$feature->id => 0],
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $this->assertDatabaseHas('features', ['id' => $feature->id, 'is_active' => false]);
    }

    public function test_admin_can_update_feature_assignments_by_plan_and_feature(): void
    {
        $admin = $this->flagAdmin();
        $feature = Feature::query()->where('code', 'customer_management')->firstOrFail();
        $trial = Package::query()->where('code', 'trial')->firstOrFail();
        $basic = Package::query()->where('code', 'basic')->firstOrFail();

        PackageFeature::query()
            ->where('package_id', $trial->id)
            ->where('feature_id', $feature->id)
            ->delete();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.package-flags.feature-assignment.update'), [
                'assignments' => [
                    $trial->id => [$feature->id => 1],
                    $basic->id => [$feature->id => 0],
                ],
            ])
            ->assertRedirect(route('admin.package-flags.index'));

        $this->assertDatabaseHas('package_features', [
            'package_id' => $trial->id,
            'feature_id' => $feature->id,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('package_features', [
            'package_id' => $basic->id,
            'feature_id' => $feature->id,
            'is_enabled' => false,
        ]);
    }

    public function test_admin_feature_creation_rejects_duplicate_codes(): void
    {
        $admin = $this->flagAdmin();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.package-flags.features.store'), [
                'name' => 'Customer management copy',
                'code' => 'customer_management',
                'description' => 'Duplicate feature.',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_admin_feature_flag_update_rejects_invalid_boolean_values(): void
    {
        $admin = $this->flagAdmin();
        $feature = Feature::query()->where('code', 'customer_management')->firstOrFail();

        $this->actingAs($admin, 'platformadmin')
            ->put(route('admin.package-flags.features.update'), [
                'features' => [$feature->id => 'invalid'],
            ])
            ->assertSessionHasErrors('features.'.$feature->id);
    }

    public function test_new_draft_plan_change_replaces_previous_unpaid_draft(): void
    {
        [$license, $unusedRequest, $unusedAdmin] = $this->downgradeFixture();
        $owner = $license->tenant->owner;
        TenantRequest::query()->whereKey($unusedRequest->id)->delete();
        $this->actingAs($owner, 'platformuser');
        $service = app(TenantRequestService::class);

        $first = $service->createRequest(new TenantRequestCreate(
            tenantId: $license->tenant_id,
            requestType: 'plan_change',
            requestedPlanType: 'basic',
            extensionMonths: 3,
        ));
        $second = $service->createRequest(new TenantRequestCreate(
            tenantId: $license->tenant_id,
            requestType: 'plan_change',
            requestedPlanType: 'basic',
            extensionMonths: 6,
        ));

        $this->assertDatabaseHas('tenant_requests', ['id' => $first->id, 'is_deleted' => true]);
        $this->assertDatabaseHas('manual_payment_requests', [
            'tenant_request_id' => $first->id,
            'is_deleted' => true,
        ]);
        $this->assertDatabaseHas('tenant_requests', ['id' => $second->id, 'is_deleted' => false]);
    }

    public function test_extension_is_rejected_while_downgrade_is_scheduled(): void
    {
        [$license, $tenantRequest, $admin] = $this->downgradeFixture();
        app(TenantLicenseService::class)->applyApprovedTenantRequest($tenantRequest, $admin->id);
        $this->actingAs($license->tenant->owner, 'platformuser');

        $this->expectException(InvalidTenantRequest::class);

        app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $license->tenant_id,
            requestType: 'extension',
            extensionMonths: 1,
        ));
    }

    protected function downgradeFixture(): array
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU00000001',
            'name' => 'Plan Owner',
            'email' => 'plan-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $admin = PlatformAdmin::query()->create([
            'code' => 'PA00000001',
            'name' => 'Plan Admin',
            'username' => 'planadmin',
            'email' => 'plan-admin@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Premium Tenant',
            'tenant_code' => 'premium-plan-tenant',
            'subdomain' => 'premium-plan-tenant',
            'status' => 'active',
        ]);
        $license = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'DOWNGRADETEST001',
            'plan_type' => 'premium',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);
        $tenantRequest = TenantRequest::query()->create([
            'code' => 'TR00000001',
            'tenant_id' => $tenant->id,
            'platform_user_id' => $owner->id,
            'request_type' => 'plan_change',
            'requested_plan_type' => 'basic',
            'extension_months' => 3,
            'total_cost' => 142500,
            'request_status' => 'pending_approval',
        ]);

        return [$license, $tenantRequest, $admin];
    }

    protected function flagAdmin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'code' => 'PA00000002',
            'name' => 'Flag Admin',
            'username' => 'flagadmin',
            'email' => 'flag-admin@example.com',
            'password' => 'strong-secret',
            'status' => 'active',
        ]);
    }
}
