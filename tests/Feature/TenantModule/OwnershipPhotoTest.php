<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipListFilter;
use App\Models\PawnModule\PawnCollateralItem;
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\{Storage, URL};
use Tests\Support\OwnershipPurchaseTestCase;

class OwnershipPhotoTest extends OwnershipPurchaseTestCase
{
    public function test_photo_reuses_inventory_signing_and_missing_file_is_null(): void
    {
        // The photo is an existing Pawn reference linked to the purchased Inventory identity.
        // A fake private disk uses the real URL signer; no new Ownership upload or file copy exists.
        Storage::fake('local');
        Storage::disk('local')->buildTemporaryUrlsUsing(fn ($path, $expiry) =>
            URL::temporarySignedRoute('storage.local', $expiry, ['path' => $path]));
        $purchase = $this->paidPurchase($this->createItem());
        $this->acquire($purchase);
        $path = 'ownership-test/private-photo.png';
        Storage::disk('local')->put($path, 'test image content');
        PawnCollateralItem::factory()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'inventory_item_id' => $purchase['item']->id, 'image_url' => $path]);
        $inventory = app(InventoryService::class)->itemDetail($purchase['item']->code)->toArray();
        $row = $this->photoRow();
        $this->assertNotNull($row['image_url']);
        $this->assertStringContainsString('signature=', $row['image_url']);
        $this->assertSame($inventory['image_url'], $row['image_url']);
        $this->assertSame($inventory['image_url_expires_at'], $row['image_url_expires_at']);
        $this->assertArrayNotHasKey('collateral_image_path', $row);
        $this->assertArrayNotHasKey('image_path', $row);
        Storage::disk('local')->delete($path);
        $missing = $this->photoRow();
        $this->assertNull($missing['image_url']);
        $this->assertNull($missing['image_url_expires_at']);
    }

    public function test_foreign_photo_reference_does_not_leak(): void
    {
        // Deliberately create a foreign collateral association to the home item. Both the collateral
        // and item tenant predicates must agree before any private file path can be signed.
        Storage::fake('local');
        $home = $this->ownershipFixture;
        $purchase = $this->paidPurchase($this->createItem());
        $this->acquire($purchase);
        $this->createTenant();
        Storage::disk('local')->put('foreign/private.png', 'foreign');
        PawnCollateralItem::factory()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'inventory_item_id' => $purchase['item']->id, 'image_url' => 'foreign/private.png']);
        $this->setTenant($home);
        $this->assertNull($this->photoRow()['image_url']);
    }

    private function photoRow(): array
    {
        return app(OwnershipService::class)->itemPage(OwnershipListFilter::fromValidated([]))->toArray()['items'][0];
    }
}
