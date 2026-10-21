<?php

namespace Tests\Feature\TenantModule;

use App\Models\InventoryModule\InventoryItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipPurchaseTestCase;

/** OWN-06a: real paid purchase sources and persisted effects; execution requires prepared MySQL. */
class OwnershipAcquisitionTest extends OwnershipPurchaseTestCase
{
    #[DataProvider('trackingModes')]
    public function test_paid_goods_are_acquired_before_receipt(string $mode, string $quantity, string $paid): void
    {
        // Arrange real source evidence without creating Ownership, receipts or stock.
        $item = $this->createItem($mode);
        $purchase = $this->paidPurchase($item, $quantity, $paid);
        $before = $this->otherState();
        $this->assertSame(0, $this->countRows('owned_items'));
        $this->assertSame(0, $this->countRows('ownership_movements'));
        $this->assertSame(0, $this->countRows('purchase_receipts'));

        // Exercise real source qualification and acquisition, not a caller-supplied paid flag.
        $result = $this->acquire($purchase);

        // Inspect the exact stored lot/movement and keep Ownership separate from custody.
        $lot = $this->assertAcquisition($purchase, $result);
        $this->assertBalances((int) $lot->owned_item_id, $quantity);
        $this->assertSame(1, $this->countRows('owned_items'));
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('ownership_movements'));
        $this->assertNoStock($item);
        $this->assertSame($before, $this->otherState());
    }

    public function test_missing_item_identity_is_created_without_receiving_stock(): void
    {
        // An unassigned source must not silently reuse an unrelated same-name Inventory item.
        $unrelated = $this->createItem(name: 'Same name');
        $purchase = $this->paidPurchase($unrelated);
        $purchase['line']->update(['inventory_item_id' => null]);
        $before = $this->otherState(includeVersions: false);

        $result = $this->acquire($purchase);

        // The source remembers its new identity while all physical and financial effects stay absent.
        $item = InventoryItem::query()->findOrFail($purchase['line']->fresh()->inventory_item_id);
        $this->assertNotSame((int) $unrelated->id, (int) $item->id);
        $this->assertSame('Same name', $item->name);
        $this->assertSame('QUANTITY', $item->tracking_mode);
        $this->assertSame((int) $this->ownershipFixture['unit']->id, (int) $item->unit_id);
        $this->assertSame(0, (int) $item->update_key);
        $purchase['item'] = $item;
        $lot = $this->assertAcquisition($purchase, $result);
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(2, $this->countRows('inventory_items'));
        $this->assertNoStock($item);
        $this->assertSame($before, $this->otherState(includeVersions: false));
    }

    public function test_same_name_goods_keep_separate_item_identities(): void
    {
        // Matching names are not authority to merge separately identified purchased goods.
        $first = $this->paidPurchase($this->createItem(name: 'Same name'));
        $second = $this->paidPurchase($this->createItem(name: 'Same name'));
        $before = $this->otherState();

        $firstLot = $this->assertAcquisition($first, $this->acquire($first));
        $secondLot = $this->assertAcquisition($second, $this->acquire($second));

        // Each source retains its own identity and exact independent Ownership balance.
        $this->assertNotSame((int) $firstLot->owned_item_id, (int) $secondLot->owned_item_id);
        $this->assertBalances((int) $firstLot->owned_item_id, '10.000');
        $this->assertBalances((int) $secondLot->owned_item_id, '10.000');
        $this->assertSame(2, $this->countRows('owned_items'));
        $this->assertSame(2, $this->countRows('acquisition_lots'));
        $this->assertSame(2, $this->countRows('ownership_movements'));
        $this->assertNoStock($first['item']);
        $this->assertNoStock($second['item']);
        $this->assertSame($before, $this->otherState());
    }

    public function test_explicit_item_reuse_keeps_one_identity_and_separate_lots(): void
    {
        // Two compatible quantity purchases explicitly select the same Inventory identity.
        $item = $this->createItem();
        $first = $this->paidPurchase($item);
        $second = $this->paidPurchase($item, '2.000', '24.68');
        $before = $this->otherState();

        $firstLot = $this->assertAcquisition($first, $this->acquire($first));
        $secondLot = $this->assertAcquisition($second, $this->acquire($second));

        // Acquisition adds a source lot without cloning the explicitly selected item.
        $this->assertSame((int) $firstLot->owned_item_id, (int) $secondLot->owned_item_id);
        $this->assertNotSame($firstLot->code, $secondLot->code);
        $this->assertBalances((int) $firstLot->owned_item_id, '12.000');
        $this->assertSame(1, $this->countRows('inventory_items'));
        $this->assertSame(1, $this->countRows('owned_items'));
        $this->assertSame(2, $this->countRows('acquisition_lots'));
        $this->assertSame(2, $this->countRows('ownership_movements'));
        $this->assertNoStock($item);
        $this->assertSame($before, $this->otherState());
    }

    public static function trackingModes(): array
    {
        // Serialized acquisition uses quantity alone; no identifiers are known before receipt.
        return ['quantity' => ['QUANTITY', '10.000', '123.40'],
            'unique' => ['UNIQUE', '1.000', '12.34'], 'serialized' => ['SERIALIZED', '3.000', '37.02']];
    }

}
