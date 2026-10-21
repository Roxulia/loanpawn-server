<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipPurchaseTestCase;

/** OWN-06b: real source eligibility and rejection effects; no mocks of paid-source authority. */
class OwnershipSourceTest extends OwnershipPurchaseTestCase
{
    #[DataProvider('ineligiblePurchases')]
    public function test_ineligible_purchase_cannot_acquire(string $status, ?string $paid, string $price): void
    {
        // Persist source facts without paying through full Purchasing or seeding Ownership.
        $purchase = $this->createPurchase($this->createItem(), paid: $paid, status: $status, unitPrice: $price);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_payment_of_another_order_does_not_settle_this_purchase(): void
    {
        // Paid goods in another order cannot supply settlement evidence for this unpaid source.
        $item = $this->createItem();
        $unpaid = $this->createPurchase($item, paid: null);
        $this->paidPurchase($item);
        $this->assertRejected($unpaid['line']->code, InvalidTenantRequest::class);
    }

    public function test_payment_must_settle_the_entire_order(): void
    {
        // Payment covers the requested line but leaves another line on its order unpaid.
        $purchase = $this->paidPurchase($this->createItem());
        $extra = $this->createPurchase($this->createItem(), quantity: '2.000', paid: null);
        $extra['line']->update(['purchase_order_id' => $purchase['order']->id]);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_missing_source_is_not_found_without_effects(): void
    {
        // A missing public reference must not create an identity or an acquisition.
        $this->assertRejected('MISSING-PURCHASE-LINE', TenantNotFound::class);
    }

    public function test_other_tenant_source_is_not_found_without_effects(): void
    {
        // Keep valid foreign evidence, then attempt acquisition from the original acting tenant.
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->paidPurchase($this->createItem());
        $this->setTenant($acting);
        $this->assertRejected($foreign['line']->code, TenantNotFound::class);
    }

    public function test_source_rejects_other_tenant_parent_order(): void
    {
        // Model an invalid stored association; source qualification must not trust the parent ID alone.
        $purchase = $this->paidPurchase($this->createItem());
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->paidPurchase($this->createItem());
        // Keep the foreign order's payable amount unchanged when replacing its fixture line.
        $foreign['line']->delete();
        $this->setTenant($acting);
        $purchase['line']->update(['purchase_order_id' => $foreign['order']->id]);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_source_rejects_other_tenant_item(): void
    {
        // A same-tenant source line cannot acquire an item belonging to another tenant.
        $purchase = $this->paidPurchase($this->createItem());
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreignItem = $this->createItem();
        $this->setTenant($acting);
        $purchase['line']->update(['inventory_item_id' => $foreignItem->id]);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_other_tenant_payment_cannot_settle_the_order(): void
    {
        // Foreign payment references cannot turn the acting tenant's unpaid source into paid goods.
        $purchase = $this->createPurchase($this->createItem(), paid: null);
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->paidPurchase($this->createItem());
        $foreign['payment']->update(['purchase_order_id' => $purchase['order']->id]);
        $this->setTenant($acting);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_source_rejects_mismatched_tracking_mode(): void
    {
        // An explicit identity must match the source metadata rather than being silently repurposed.
        $purchase = $this->paidPurchase($this->createItem('QUANTITY'));
        $purchase['line']->update(['tracking_mode' => 'SERIALIZED']);
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_zero_total_purchase_acquires_without_payment(): void
    {
        // Nothing is due on a confirmed free purchase; do not create a fake zero-value payment.
        $purchase = $this->createPurchase($this->createItem(), unitPrice: '0.00', paid: null);
        $this->assertNull($purchase['payment']);
        $this->assertSame(0, $this->countRows('purchase_payments'));
        $before = $this->otherState();

        $result = $this->acquire($purchase);

        // Record zero cost on the order date while retaining exact owned quantity and no stock.
        $lot = $this->assertAcquisition($purchase, $result, expectedCost: '0.0000', expectedDate: '2026-10-15');
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(1, $this->countRows('owned_items'));
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('ownership_movements'));
        $this->assertNoStock($purchase['item']);
        $this->assertSame($before, $this->otherState());
    }

    public static function ineligiblePurchases(): array
    {
        // A one-cent shortfall is still unpaid; status and negative totals are separate rules.
        return [
            'unpaid' => ['CONFIRMED', null, '12.34'],
            'short paid' => ['CONFIRMED', '123.39', '12.34'],
            'draft' => ['DRAFT', '123.40', '12.34'],
            'cancelled' => ['CANCELLED', '123.40', '12.34'],
            'negative total' => ['CONFIRMED', null, '-12.34'],
        ];
    }

}
