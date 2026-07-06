<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\ExpenseType;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\ItemCategoryType;
use App\Models\CoreModule\MaterialType;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\DefaultDataService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DefaultDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_paginates_item_category_types_for_current_tenant(): void
    {
        $tenant = $this->createTenant('demo-tenant', 'demo-subdomain');
        $otherTenant = $this->createTenant('other-tenant', 'other-subdomain');
        app(TenantContext::class)->set($tenant);

        foreach (range(1, 6) as $index) {
            ItemCategoryType::query()->create([
                'tenant_id' => null,
                'code' => 'default_'.$index,
                'name' => 'Default '.$index,
                'is_default' => true,
            ]);
        }

        ItemCategoryType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'current_tenant',
            'name' => 'Current Tenant',
            'is_default' => false,
        ]);

        ItemCategoryType::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'other_tenant',
            'name' => 'Other Tenant',
            'is_default' => false,
        ]);

        $this->app->instance('request', Request::create('/tenant/item-category-types?page=1'));

        $page = app(DefaultDataService::class)->listItemCategoryTypes(5);

        $this->assertCount(5, $page->items);
        $this->assertSame(1, $page->currentPage);
        $this->assertSame(2, $page->lastPage);
        $this->assertSame(5, $page->perPage);
        $this->assertSame(7, $page->total);
        $this->assertNotContains('other_tenant', array_column($page->items, 'code'));
    }

    public function test_plain_item_category_route_still_returns_array_and_paginated_route_returns_page(): void
    {
        $this->withoutMiddleware();
        $tenant = $this->createTenant('demo-tenant', 'demo-subdomain');
        app(TenantContext::class)->set($tenant);

        ItemCategoryType::query()->create([
            'tenant_id' => null,
            'code' => 'default_1',
            'name' => 'Default 1',
            'is_default' => true,
        ]);

        $plainResponse = $this->getJson('/api/tenant/item-category-types');

        $plainResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', 'default_1')
            ->assertJsonMissingPath('data.items');

        $paginatedResponse = $this->getJson('/api/tenant/item-category-types/paginated?per_page=5');

        $paginatedResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.code', 'default_1')
            ->assertJsonPath('data.per_page', 5)
            ->assertJsonPath('data.total', 1);
    }

    public function test_it_paginates_interest_expense_and_material_types_for_current_tenant(): void
    {
        $tenant = $this->createTenant('demo-tenant', 'demo-subdomain');
        $otherTenant = $this->createTenant('other-tenant', 'other-subdomain');
        app(TenantContext::class)->set($tenant);

        $this->seedTypeRows(InterestType::class, $tenant->id, $otherTenant->id, ['duration_in_days' => 30]);
        $this->seedTypeRows(ExpenseType::class, $tenant->id, $otherTenant->id);
        $this->seedTypeRows(MaterialType::class, $tenant->id, $otherTenant->id);

        $this->app->instance('request', Request::create('/tenant/interest-types?page=1'));
        $interestPage = app(DefaultDataService::class)->listInterestTypes(5);

        $this->app->instance('request', Request::create('/tenant/expense-types?page=1'));
        $expensePage = app(DefaultDataService::class)->listExpenseTypes(5);

        $this->app->instance('request', Request::create('/tenant/material-types?page=1'));
        $materialPage = app(DefaultDataService::class)->listMaterialTypes(5);

        foreach ([$interestPage, $expensePage, $materialPage] as $page) {
            $this->assertCount(5, $page->items);
            $this->assertSame(1, $page->currentPage);
            $this->assertSame(2, $page->lastPage);
            $this->assertSame(5, $page->perPage);
            $this->assertSame(7, $page->total);
            $this->assertNotContains('other_tenant', array_column($page->items, 'code'));
        }
    }

    protected function seedTypeRows(string $modelClass, int $tenantId, int $otherTenantId, array $extra = []): void
    {
        foreach (range(1, 6) as $index) {
            $modelClass::query()->create($extra + [
                'tenant_id' => null,
                'code' => 'default_'.$index,
                'name' => 'Default '.$index,
                'is_default' => true,
            ]);
        }

        $modelClass::query()->create($extra + [
            'tenant_id' => $tenantId,
            'code' => 'current_tenant',
            'name' => 'Current Tenant',
            'is_default' => false,
        ]);

        $modelClass::query()->create($extra + [
            'tenant_id' => $otherTenantId,
            'code' => 'other_tenant',
            'name' => 'Other Tenant',
            'is_default' => false,
        ]);
    }

    protected function createTenant(string $tenantCode, string $subdomain): Tenant
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
            'name' => 'Demo Tenant',
            'tenant_code' => $tenantCode,
            'subdomain' => $subdomain,
            'status' => 'active',
        ]);
    }
}
