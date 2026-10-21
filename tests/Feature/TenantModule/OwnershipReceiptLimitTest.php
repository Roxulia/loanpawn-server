<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\TenantNotFound;
use Tests\Support\OwnershipReceiptTestCase;

class OwnershipReceiptLimitTest extends OwnershipReceiptTestCase
{
    public function test_link_rejects_one_thousandth_above_received_quantity(): void
    {
        // Ten units are owned, but only four arrived. Acquisition quantity must not allow linking
        // an extra 0.001 that has no receipt provenance; the successful receipt must remain intact.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $this->assertLinkRejected($goods['lot'], $receipt, '4.001');
    }

    public function test_partial_links_exhaust_only_their_receipt_capacity(): void
    {
        // Link one then three from the same four-unit receipt using distinct intent keys.
        // A third fresh-key request cannot allocate the already linked receipt quantity again.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $this->linkReceipt($goods['lot'], $receipt, '1.000', key: 'part-one');
        $this->linkReceipt($goods['lot'], $receipt, '3.000', key: 'part-two');
        $this->assertLinkRejected($goods['lot'], $receipt, '0.001');
    }

    public function test_new_key_cannot_duplicate_a_completed_link(): void
    {
        // This is source-capacity rejection, not same-key idempotent replay. A new key cannot
        // turn an already linked four-unit receipt into another four units of received Ownership.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $this->linkReceipt($goods['lot'], $receipt, '4.000');
        $this->assertLinkRejected($goods['lot'], $receipt, '4.000');
    }

    public function test_receipts_cannot_allocate_more_than_original_acquisition(): void
    {
        // Model an unexpected extra physical receipt after all ten purchased units were linked.
        // That receipt stays recorded, but cannot expand the original purchase lot to eleven units.
        $goods = $this->goods();
        $first = $this->receivePurchase($goods['purchase'], '10.000');
        $this->linkReceipt($goods['lot'], $first, '10.000');
        $extra = $this->receivePurchase($goods['purchase'], '1.000');
        $this->assertLinkRejected($goods['lot'], $extra, '1.000');
    }

    public function test_same_item_receipt_cannot_link_to_another_purchase_lot(): void
    {
        // Two lots intentionally share an item. Name/item equality cannot replace the canonical
        // purchase-line association of this receipt, even when the other lot has unused capacity.
        $goods = $this->goods();
        $other = $this->paidPurchase($goods['item']);
        $otherLot = $this->assertAcquisition($other, $this->acquire($other));
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $this->assertLinkRejected($otherLot, $receipt, '4.000');
    }

    public function test_other_item_receipt_cannot_link_to_this_lot(): void
    {
        // A receipt from a separately identified item is not interchangeable with this source,
        // even if both item descriptions happen to match and both purchases are fully paid.
        $goods = $this->goods();
        $other = $this->goods();
        $receipt = $this->receivePurchase($other['purchase'], '4.000');
        $this->assertLinkRejected($goods['lot'], $receipt, '4.000');
    }

    public function test_foreign_receipt_is_not_visible_to_the_acting_tenant(): void
    {
        // Keep both tenants' real receipts and ownership rows in the no-write snapshot.
        // The acting tenant cannot claim a foreign receipt by submitting its public code.
        $goods = $this->goods();
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->goods();
        $receipt = $this->receivePurchase($foreign['purchase'], '4.000');
        $this->setTenant($acting);
        $this->assertLinkRejected($goods['lot'], $receipt, '4.000', exception: TenantNotFound::class);
    }

    public function test_foreign_lot_is_not_visible_to_the_acting_tenant(): void
    {
        // Reversing the association is also forbidden: a valid local receipt cannot mutate a
        // foreign lot supplied through path codes. Both fixtures must remain unchanged on rejection.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->goods();
        $this->setTenant($acting);
        $this->assertLinkRejected($foreign['lot'], $receipt, '4.000', exception: TenantNotFound::class);
    }
}
