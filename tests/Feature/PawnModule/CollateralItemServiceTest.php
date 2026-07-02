<?php

namespace Tests\Feature\PawnModule;

use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\PawnCollateralItemUpdate;
use App\Models\CoreModule\ItemCategoryType;
use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PawnModule\CollateralItemService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CollateralItemServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_updates_and_deletes_normal_item(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant);
        $watchCategory = ItemCategoryType::query()->create([
            'tenant_id' => null,
            'code' => 'watches',
            'name' => 'Watches',
            'is_default' => true,
        ]);
        $carCategory = ItemCategoryType::query()->create([
            'tenant_id' => null,
            'code' => 'car',
            'name' => 'Car',
            'is_default' => true,
        ]);

        $created = app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Normal',
            name: 'Laptop',
            description: 'MacBook Pro',
            brandName: 'Apple',
            estimatedValue: 1500,
            itemCategoryTypeId: $watchCategory->id,
        ));

        $updated = app(CollateralItemService::class)->update(new PawnCollateralItemUpdate(
            itemId: $created->id,
            description: 'MacBook Pro 16',
            itemCategoryTypeId: $carCategory->id,
            itemStatus: 'inactive',
        ));

        $this->assertSame('MacBook Pro 16', $updated->description);
        $this->assertSame($carCategory->id, $updated->itemCategoryTypeId);
        $this->assertSame('Car', $updated->itemCategoryTypeName);
        $this->assertSame('inactive', $updated->itemStatus);

        app(CollateralItemService::class)->delete($created->id);

        $this->assertSoftDeleted('pawn_collateral_items', [
            'id' => $created->id,
        ]);
    }

    public function test_it_seeds_default_item_category_types(): void
    {
        $this->seed(\Database\Seeders\ItemCategoryTypeSeeder::class);

        $this->assertDatabaseHas('item_category_types', [
            'tenant_id' => null,
            'code' => 'watches',
            'name' => 'Watches',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('item_category_types', [
            'tenant_id' => null,
            'code' => 'real_estate',
            'name' => 'Real Estate',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('item_category_types', [
            'tenant_id' => null,
            'code' => 'car',
            'name' => 'Car',
            'is_default' => true,
        ]);
    }

    public function test_it_creates_updates_and_deletes_jewellery_item(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant);
        $materialType = MaterialType::query()->create([
            'tenant_id' => null,
            'code' => 'gold',
            'name' => 'Gold',
            'is_default' => true,
        ]);

        $created = app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Jewellery',
            name: 'Necklace',
            materialTypeId: $materialType->id,
            kyat: 1,
            pal: 2,
            yway: 3,
            containsGemstones: true,
            gemstoneDetails: ['ruby'],
        ));

        $updated = app(CollateralItemService::class)->update(new PawnCollateralItemUpdate(
            itemId: $created->id,
            pal: 4,
            containsGemstones: false,
        ));

        $this->assertSame('4.00', $updated->pal);
        $this->assertFalse($updated->containsGemstones);

        app(CollateralItemService::class)->delete($created->id);

        $this->assertSoftDeleted('pawn_collateral_items', [
            'id' => $created->id,
        ]);
    }

    public function test_it_lists_normal_and_jewellery_items_from_unified_collateral_table(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant);
        $materialType = MaterialType::query()->create([
            'tenant_id' => null,
            'code' => 'gold',
            'name' => 'Gold',
            'is_default' => true,
        ]);

        app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Normal',
            name: 'Camera',
            description: 'Sony Mirrorless',
            brandName: 'Sony',
        ));

        app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Jewellery',
            name: 'Ring',
            materialTypeId: $materialType->id,
            kyat: 1,
            pal: 2,
            yway: 3,
            containsGemstones: true,
        ));

        $list = app(CollateralItemService::class)->list();

        $this->assertCount(2, $list->items);
        $this->assertContains('Normal', array_map(fn ($item) => $item->itemType, $list->items));
        $this->assertContains('Jewellery', array_map(fn ($item) => $item->itemType, $list->items));

        $jewelleryItem = collect($list->items)->first(fn ($item) => $item->itemType === 'Jewellery');
        $normalItem = collect($list->items)->first(fn ($item) => $item->itemType === 'Normal');

        $this->assertSame('Ring', $jewelleryItem->name);
        $this->assertNull($jewelleryItem->description);
        $this->assertSame('Camera', $normalItem->name);
        $this->assertSame('Sony Mirrorless', $normalItem->description);
    }

    public function test_it_shows_collateral_item_detail(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant);

        $created = app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Normal',
            name: 'Detail Camera',
            description: 'Sony Mirrorless',
        ));

        $detail = app(CollateralItemService::class)->show($created->id);

        $this->assertSame($created->id, $detail->id);
        $this->assertSame('Detail Camera', $detail->name);
        $this->assertSame('Sony Mirrorless', $detail->description);
    }

    public function test_it_searches_collateral_items_by_name_description_type_or_status(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant);

        app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Normal',
            name: 'Camera',
            description: 'Sony Mirrorless',
        ));

        app(CollateralItemService::class)->create(new PawnCollateralItemCreate(
            type: 'Normal',
            name: 'Phone',
            description: 'iPhone',
            itemStatus: 'inactive',
        ));

        $list = app(CollateralItemService::class)->list(15, 'mirrorless');

        $this->assertSame(1, $list->total);
        $this->assertSame('Camera', $list->items[0]->name);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'phone' => '09999999999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant',
            'tenant_code' => 'demo-tenant',
            'subdomain' => 'demo-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant): TenantUser
    {
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
            'username' => 'USER0001',
            'name' => 'Tenant User',
            'nrc' => '12/PaTaNa(N)000001',
            'email' => 'tenant-user@example.com',
            'phone' => '0955555555',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }
}
