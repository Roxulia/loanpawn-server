<?php

namespace Tests\Feature\TenantModule;

use Tests\Support\OwnershipHttpTestCase;

class OwnershipReplayScopeTest extends OwnershipHttpTestCase
{
    public function test_changed_link_payload_conflicts_without_effects(): void
    {
        $goods = $this->goods('QUANTITY', '2.000', '24.68');
        $receipt = $this->receivePurchase($goods['purchase'], '2.000');
        $this->login();
        $payload = $this->linkPayload($goods, $receipt);
        $this->httpLink($goods, $payload, 'same-link-key')->assertOk();
        $before = $this->receiptState();
        $payload['quantity'] = '2.000';
        $this->httpLink($goods, $payload, 'same-link-key')->assertConflict();
        $this->assertSame($before, $this->receiptState());
    }

    public function test_same_key_is_scoped_by_operation(): void
    {
        // Acquisition and linking intentionally use the same key. Different operation names must
        // not replay an acquisition result as a link, or report a changed-payload conflict.
        $purchase = $this->paidPurchase($this->createItem(), '1.000', '12.34');
        $lot = $this->assertAcquisition($purchase, $this->acquireCode($purchase['line']->code, 'shared-key'));
        $receipt = $this->receivePurchase($purchase, '1.000');
        $this->linkReceipt($lot, $receipt, '1.000', [], 'shared-key');
        $this->assertSame(1, \App\Models\OwnershipModule\OwnershipReceiptLink::query()->where('acquisition_lot_id', $lot->id)->count());
    }

    public function test_same_key_is_scoped_by_tenant(): void
    {
        $first = $this->paidPurchase($this->createItem(), '1.000', '12.34');
        $original = $this->acquireCode($first['line']->code, 'tenant-shared-key');
        $this->createTenant();
        $second = $this->paidPurchase($this->createItem(), '1.000', '12.34');
        $other = $this->acquireCode($second['line']->code, 'tenant-shared-key');
        $lot = $this->assertAcquisition($second, $other);
        $this->assertNotSame($original->toArray(), $other->toArray());
        $this->assertSame((int) $this->ownershipFixture['tenant']->id, (int) $lot->tenant_id);
    }
}
