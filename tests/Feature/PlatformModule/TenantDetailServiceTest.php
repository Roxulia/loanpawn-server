<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\TenantCreate;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantBranding;
use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Services\PlatformModule\TenantServices\TenantDetailService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantDetailServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_it_can_build_tenant_detail_by_tenant_id(): void
    {
        Carbon::setTestNow('2026-04-16 09:00:00');
        $this->createDefaultAdminRole();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Detail Owner',
            'email' => 'detail-owner@example.com',
            'phone' => '09666666666',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Detail Tenant',
            code: null,
            subdomain: 'detail-subdomain',
            createdByAdmin: false,
            planType: null,
            address: 'No. 11 Detail Road',
            phone: '09444444444',
            city: 'Yangon',
            country: 'Myanmar',
        ));

        $tenantBranding = TenantBranding::query()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $tenantBranding->fill([
            'primary_color' => '#123456',
            'secondary_color' => '#654321',
            'accent_color' => '#ABCDEF',
            'slip_header_layout' => ['title' => 'Detail Header'],
            'slip_footer_layout' => ['text' => 'Detail Footer'],
        ])->save();

        $tenant->license->update([
            'current_month_slip_count' => 7,
            'current_staff_count' => 3,
            'current_account_count' => 1,
            'current_currency_type_count' => 2,
            'current_exchange_pair_count' => 1,
        ]);

        $detail = app(TenantDetailService::class)->findByTenantId($tenant->id);

        $this->assertSame('Detail Tenant', $detail->name);
        $this->assertNull($detail->subdomain);
        $this->assertMatchesRegularExpression('/^DET[A-Z0-9]{4}$/', $detail->code);
        $this->assertSame('No. 11 Detail Road', $detail->tenant_contact->address);
        $this->assertSame('09444444444', $detail->tenant_contact->phone);
        $this->assertSame('Yangon', $detail->tenant_contact->city);
        $this->assertSame('Myanmar', $detail->tenant_contact->country);
        $this->assertSame('trial', $detail->tenant_license->planType);
        $this->assertSame('active', $detail->tenant_license->status);
        $this->assertSame(7, $detail->tenant_license->currentMonthSlipCount);
        $this->assertSame(3, $detail->tenant_license->currentStaffCount);
        $this->assertSame(1, $detail->tenant_license->currentAccountCount);
        $this->assertSame(2, $detail->tenant_license->currentCurrencyTypeCount);
        $this->assertSame(1, $detail->tenant_license->currentExchangePairCount);
        $this->assertSame($tenant->license->plan->max_slip_per_month, $detail->tenant_license->maxSlipPerMonth);
        $this->assertSame($tenant->license->plan->max_staff_count, $detail->tenant_license->maxStaffCount);
        $this->assertSame($tenant->license->plan->max_account_count, $detail->tenant_license->maxAccountCount);
        $this->assertSame($tenant->license->plan->max_currency_type_count, $detail->tenant_license->maxCurrencyTypeCount);
        $this->assertSame($tenant->license->plan->max_exchange_pair_count, $detail->tenant_license->maxExchangePairCount);
        $this->assertSame(7, $detail->toArray()['tenant_license']['current_month_slip_count']);
        $this->assertSame('#123456', $detail->tenant_branding?->primaryColor);
        $this->assertSame(['title' => 'Detail Header'], $detail->tenant_branding?->slipHeaderLayout);
        $features = $detail->tenant_features->toArray();
        $this->assertTrue($features['customer_management']['is_active']);
        $this->assertTrue($features['customer_management']['is_enabled']);
        $this->assertTrue($detail->toArray()['tenant_features']['customer_management']['is_enabled']);
        $this->assertTrue($features['online_sync']['is_active']);
        $this->assertSame(['code', 'is_active', 'is_enabled', 'unlock_in'], array_keys($features['online_sync']));
        $this->assertFalse($features['online_sync']['is_enabled']);
        $this->assertSame(['code' => 'basic', 'name' => 'Basic'], $features['online_sync']['unlock_in']);

        Carbon::setTestNow();
    }

    public function test_it_can_build_tenant_detail_by_tenant_code_and_subdomain(): void
    {
        Carbon::setTestNow('2026-04-16 09:00:00');
        $this->createDefaultAdminRole();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Lookup Owner',
            'email' => 'lookup-owner@example.com',
            'phone' => '09777777777',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $platformAdmin = PlatformAdmin::query()->create([
            'code' => 'PA'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Platform Admin',
            'username' => 'platform-admin',
            'email' => 'admin@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformAdmin, 'platformadmin');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Lookup Tenant',
            code: 'lookup-tenant',
            subdomain: 'lookup-subdomain',
            createdByAdmin: true,
            planType: 'premium',
            status: 'active',
            platformUserId: $platformUser->id,
            address: 'No. 22 Lookup Road',
            phone: '09555555555',
            city: 'Bago',
            country: 'Myanmar',
        ));

        $detailByCode = app(TenantDetailService::class)->findByTenantCode($tenant->tenant_code);
        $detailBySubdomain = app(TenantDetailService::class)->findBySubdomain($tenant->subdomain);

        Feature::query()->where('code', 'tenant_branding')->update(['is_active' => false]);

        $detailAfterFeatureChange = app(TenantDetailService::class)->findByTenantCode($tenant->tenant_code);

        $this->assertSame($tenant->tenant_code, $detailByCode->code);
        $this->assertSame('No. 22 Lookup Road', $detailByCode->tenant_contact->address);
        $this->assertTrue($detailByCode->tenant_features->toArray()['tenant_branding']['is_enabled']);
        $this->assertSame(['code' => 'premium', 'name' => 'Premium'], $detailByCode->tenant_features->toArray()['tenant_branding']['unlock_in']);
        $this->assertTrue($detailByCode->tenant_features->toArray()['online_sync']['is_enabled']);
        $this->assertFalse($detailAfterFeatureChange->tenant_features->toArray()['tenant_branding']['is_active']);
        $this->assertTrue($detailAfterFeatureChange->tenant_features->toArray()['tenant_branding']['is_enabled']);
        $this->assertSame($tenant->subdomain, $detailBySubdomain->subdomain);
        $this->assertSame('09555555555', $detailBySubdomain->tenant_contact->phone);

        Carbon::setTestNow();
    }

    protected function createDefaultAdminRole(): TenantRole
    {
        TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Owner',
            'description' => 'Default owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);

        return TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Admin',
            'description' => 'Default admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
    }
}
