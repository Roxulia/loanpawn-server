<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\StoreFinancialAccountTypeRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountTypeRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\FinancialAccountTypes;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\FinancialAccountTypeService;
use App\Support\TenantContext;
use Database\Seeders\FinancialAccountTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountTypeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_financial_account_types_are_seeded_idempotently(): void
    {
        $this->seed(FinancialAccountTypeSeeder::class);
        $this->seed(FinancialAccountTypeSeeder::class);

        $this->assertDatabaseCount('financial_account_types', 3);
        $this->assertSame(
            ['Bank', 'Cash', 'Online Pay'],
            FinancialAccountTypes::query()->whereNull('tenant_id')->orderBy('name')->pluck('name')->all()
        );
    }

    public function test_list_returns_active_defaults_and_current_tenant_types_as_default_data_page(): void
    {
        $tenant = $this->createTenant('financial-tenant', 'financial-subdomain');
        $otherTenant = $this->createTenant('other-financial-tenant', 'other-financial-subdomain');
        app(TenantContext::class)->set($tenant);
        $this->seed(FinancialAccountTypeSeeder::class);

        FinancialAccountTypes::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'mobile_wallet',
            'name' => 'Mobile Wallet',
            'is_active' => true,
        ]);
        FinancialAccountTypes::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'inactive_type',
            'name' => 'Inactive Type',
            'is_active' => false,
        ]);
        FinancialAccountTypes::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'other_tenant_type',
            'name' => 'Other Tenant Type',
            'is_active' => true,
        ]);

        $page = app(FinancialAccountTypeService::class)->list(2);

        $this->assertCount(2, $page->items);
        $this->assertSame(1, $page->currentPage);
        $this->assertSame(2, $page->lastPage);
        $this->assertSame(2, $page->perPage);
        $this->assertSame(4, $page->total);

        $codes = array_column(app(FinancialAccountTypeService::class)->list(100)->items, 'code');
        sort($codes);
        $this->assertSame(
            ['bank', 'cash', 'mobile_wallet', 'online_pay'],
            $codes
        );
    }

    public function test_list_endpoint_returns_default_data_page_shape(): void
    {
        $this->withoutMiddleware();
        $tenant = $this->createTenant('route-tenant', 'route-subdomain');
        app(TenantContext::class)->set($tenant);
        $this->seed(FinancialAccountTypeSeeder::class);

        $this->getJson('/api/tenant/financial-account-types?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3);
    }

    public function test_it_creates_soft_deletes_and_reactivates_an_owned_type(): void
    {
        $tenant = $this->createTenant('crud-tenant', 'crud-subdomain');
        app(TenantContext::class)->set($tenant);
        $service = app(FinancialAccountTypeService::class);

        $created = $service->create(new StoreFinancialAccountTypeRequest('Mobile Pay', 'Mobile Payment'));
        $this->assertSame('mobile_pay', $created->code);
        $this->assertSame($tenant->id, $created->tenantId);

        $service->delete('mobile_pay');
        $this->assertDatabaseHas('financial_account_types', ['id' => $created->id, 'is_active' => false, 'update_key' => 1]);

        $reactivated = $service->create(new StoreFinancialAccountTypeRequest('mobile_pay', 'Mobile Wallet'));
        $this->assertSame($created->id, $reactivated->id);
        $this->assertSame('Mobile Wallet', $reactivated->name);
        $this->assertSame(2, $reactivated->updateKey);
        $this->assertTrue($reactivated->isActive);
    }

    public function test_it_updates_owned_type_by_current_code_and_rejects_stale_update(): void
    {
        $tenant = $this->createTenant('update-tenant', 'update-subdomain');
        app(TenantContext::class)->set($tenant);
        $service = app(FinancialAccountTypeService::class);
        $created = $service->create(new StoreFinancialAccountTypeRequest('wallet', 'Wallet'));

        $updated = $service->update('wallet', new UpdateFinancialAccountTypeRequest('mobile_wallet', 'Mobile Wallet', $created->updateKey));

        $this->assertSame('mobile_wallet', $updated->code);
        $this->assertSame(1, $updated->updateKey);

        $this->expectException(InvalidTenantRequest::class);
        $service->update('mobile_wallet', new UpdateFinancialAccountTypeRequest('wallet', 'Stale Wallet', 0));
    }

    public function test_active_owned_duplicate_is_rejected(): void
    {
        $tenant = $this->createTenant('duplicate-tenant', 'duplicate-subdomain');
        app(TenantContext::class)->set($tenant);
        $service = app(FinancialAccountTypeService::class);
        $service->create(new StoreFinancialAccountTypeRequest('wallet', 'Wallet'));

        $this->expectException(InvalidTenantRequest::class);
        $service->create(new StoreFinancialAccountTypeRequest('Wallet', 'Another Wallet'));
    }

    public function test_default_and_other_tenant_types_cannot_be_modified(): void
    {
        $tenant = $this->createTenant('owner-tenant', 'owner-subdomain');
        $otherTenant = $this->createTenant('foreign-tenant', 'foreign-subdomain');
        app(TenantContext::class)->set($tenant);
        $this->seed(FinancialAccountTypeSeeder::class);
        FinancialAccountTypes::query()->create(['tenant_id' => $otherTenant->id, 'code' => 'foreign', 'name' => 'Foreign', 'is_active' => true]);
        $service = app(FinancialAccountTypeService::class);

        try {
            $service->delete('bank');
            $this->fail('Platform defaults must not be deleted.');
        } catch (TenantAccessDenied) {
            $this->assertDatabaseHas('financial_account_types', ['tenant_id' => null, 'code' => 'bank', 'is_active' => true]);
        }

        $this->expectException(TenantAccessDenied::class);
        $service->update('foreign', new UpdateFinancialAccountTypeRequest('foreign', 'Changed', 0));
    }

    private function createTenant(string $tenantCode, string $subdomain): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User',
            'email' => $tenantCode.'@example.com',
            'phone' => '09999999999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Financial Tenant',
            'tenant_code' => $tenantCode,
            'subdomain' => $subdomain,
            'status' => 'active',
        ]);
    }
}
