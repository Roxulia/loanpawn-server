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

    public function test_existing_license_keeps_enabled_features_when_plan_sales_are_disabled(): void
    {
        Package::query()->where('code', 'basic')->update(['is_active' => false]);
        $service = app(PackageService::class);

        $this->assertTrue($service->planHasFeature('basic', 'customer_management'));
        $this->assertNull($service->activePaidPackagesExcept()->firstWhere('code', 'basic'));

        Feature::query()->where('code', 'customer_management')->update(['is_active' => false]);

        $this->assertFalse($service->planHasFeature('basic', 'customer_management'));
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
        $admin = PlatformAdmin::query()->create([
            'code' => 'PA00000002',
            'name' => 'Flag Admin',
            'username' => 'flagadmin',
            'email' => 'flag-admin@example.com',
            'password' => 'strong-secret',
            'status' => 'active',
        ]);
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
}
