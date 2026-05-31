<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\TenantCreate;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantBranding;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Services\PlatformModule\TenantServices\TenantDetailService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantDetailServiceTest extends TestCase
{
    use RefreshDatabase;

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

        TenantBranding::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->code,
            'primary_color' => '#123456',
            'secondary_color' => '#654321',
            'accent_color' => '#ABCDEF',
            'slip_header_text' => 'Detail Header',
            'slip_footer_text' => 'Detail Footer',
        ]);

        $detail = app(TenantDetailService::class)->findByTenantId($tenant->id);

        $this->assertSame('Detail Tenant', $detail->name);
        $this->assertNull($detail->subdomain);
        $this->assertMatchesRegularExpression('/^DT[0-9]{4}$/', $detail->code);
        $this->assertSame('No. 11 Detail Road', $detail->tenant_contact->address);
        $this->assertSame('09444444444', $detail->tenant_contact->phone);
        $this->assertSame('Yangon', $detail->tenant_contact->city);
        $this->assertSame('Myanmar', $detail->tenant_contact->country);
        $this->assertSame('trial', $detail->tenant_license->planType);
        $this->assertSame('active', $detail->tenant_license->status);
        $this->assertSame('#123456', $detail->tenant_branding?->primaryColor);
        $this->assertSame('Detail Header', $detail->tenant_branding?->slipHeaderText);

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

        $this->assertSame($tenant->tenant_code, $detailByCode->code);
        $this->assertSame('No. 22 Lookup Road', $detailByCode->tenant_contact->address);
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
