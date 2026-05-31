<?php

namespace Tests\Feature\PlatformModule;

use App\Exceptions\FeatureNotAvailableForPlan;
use App\Exceptions\TenantAccessDenied;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Services\PlatformModule\TenantServices\TenantBrandingService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_premium_tenant_can_create_branding(): void
    {
        $tenant = $this->createTenantWithLicense('premium');

        app(TenantContext::class)->set($tenant->id);

        $branding = app(TenantBrandingService::class)->createTenantBranding([
            'logo_path' => 'branding/logo.png',
            'favicon_path' => 'branding/favicon.ico',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'accent_color' => '#778899',
        ]);

        $this->assertDatabaseHas('tenant_branding', [
            'id' => $branding->id,
            'tenant_id' => $tenant->id,
            'logo_path' => 'branding/logo.png',
            'favicon_path' => 'branding/favicon.ico',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
            'accent_color' => '#778899',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_non_premium_tenant_cannot_create_branding(): void
    {
        $tenant = $this->createTenantWithLicense('basic');

        app(TenantContext::class)->set($tenant->id);

        $this->expectException(FeatureNotAvailableForPlan::class);

        app(TenantBrandingService::class)->createTenantBranding([
            'primary_color' => '#112233',
        ]);
    }

    public function test_plan_feature_lookup_uses_package_feature_definitions(): void
    {
        $premiumTenant = $this->createTenantWithLicense('premium');
        $basicTenant = $this->createTenantWithLicense('basic', 'basic-tenant-2', 'basic-subdomain-2');
        $service = app(TenantLicenseService::class);

        $this->assertTrue($service->tenantHasFeature($premiumTenant->id, 'tenant_branding'));
        $this->assertFalse($service->tenantHasFeature($basicTenant->id, 'tenant_branding'));
        $this->assertTrue($service->tenantHasFeature($basicTenant->id, 'loan_contract_management'));
    }

    public function test_platform_user_can_create_branding_for_owned_tenant_from_platform(): void
    {
        $tenant = $this->createTenantWithLicense('premium');

        $this->actingAs($tenant->owner, 'platformuser');

        $branding = app(TenantBrandingService::class)->createTenantBranding([
            'primary_color' => '#ABCDEF',
            'slip_header_text' => 'Platform Header',
        ], $tenant->id);

        $this->assertDatabaseHas('tenant_branding', [
            'id' => $branding->id,
            'tenant_id' => $tenant->id,
            'primary_color' => '#ABCDEF',
            'slip_header_text' => 'Platform Header',
        ]);
    }

    public function test_platform_user_cannot_create_branding_for_other_users_tenant(): void
    {
        $tenant = $this->createTenantWithLicense('premium');

        $otherPlatformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($otherPlatformUser, 'platformuser');
        $this->expectException(TenantAccessDenied::class);

        app(TenantBrandingService::class)->createTenantBranding([
            'primary_color' => '#ABCDEF',
        ], $tenant->id);
    }

    public function test_it_can_store_uploaded_branding_images(): void
    {
        Storage::fake('public');

        $tenant = $this->createTenantWithLicense('premium');

        app(TenantContext::class)->set($tenant->id);

        $branding = app(TenantBrandingService::class)->createTenantBranding([
            'logo_file' => UploadedFile::fake()->image('logo.png'),
            'favicon_file' => UploadedFile::fake()->image('favicon.png', 64, 64),
            'primary_color' => '#123456',
        ]);

        $this->assertNotNull($branding->logo_path);
        $this->assertNotNull($branding->favicon_path);
        Storage::disk('public')->assertExists($branding->logo_path);
        Storage::disk('public')->assertExists($branding->favicon_path);

        app(TenantContext::class)->clear();
    }

    protected function createTenantWithLicense(string $planType, ?string $tenantCode = null, ?string $subdomain = null): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Demo User',
            'email' => ($tenantCode ?? $planType).'@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => ucfirst($planType).' Tenant',
            'tenant_code' => $tenantCode ?? $planType.'-tenant',
            'subdomain' => $subdomain ?? $planType.'-subdomain',
            'status' => 'active',
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => strtoupper(substr(str_pad($tenant->tenant_code, 16, 'X'), 0, 16)),
            'plan_type' => $planType,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        return $tenant;
    }
}
