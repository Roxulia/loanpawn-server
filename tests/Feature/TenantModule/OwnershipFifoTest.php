<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\Currency;
use App\DataObjects\RequestObjects\InventoryReservationRequest;
use App\Services\TenantModule\InventoryService;
use Tests\Support\OwnershipFifoTestCase;

/** Dedicated selection contracts; allocation alone must never reduce or reserve stock. */
class OwnershipFifoTest extends OwnershipFifoTestCase
{
    public function test_one_lot_and_exact_final_exhaustion(): void
    {
        // A request equal to the available lot allocates all three without changing its balance.
        $goods = $this->fifoGoods();
        $this->assertFifo($goods, '3.000', [$this->fifoRow($goods, '3.000')]);
    }

    public function test_oldest_date_precedes_insert_order_and_final_lot_is_partial(): void
    {
        // Create the newer lot first. Request six: old lot supplies three, newer supplies three of five.
        $new = $this->fifoGoods(quantity: '5.000', date: '2026-10-12');
        $old = $this->fifoGoods($new['item'], date: '2026-10-01');
        $this->assertFifo($new, '6.000', [$this->fifoRow($old, '3.000'), $this->fifoRow($new, '3.000')]);
    }

    public function test_equal_dates_use_lot_id_and_decimal_allocation_is_exact(): void
    {
        // The second lot supplies the final thousandth; no float rounding may conceal that split.
        $first = $this->fifoGoods(quantity: '0.001');
        $second = $this->fifoGoods($first['item'], quantity: '0.002');
        $this->assertFifo($first, '0.002', [$this->fifoRow($first, '0.001'), $this->fifoRow($second, '0.001')]);
    }

    public function test_multiple_locations_use_location_id_order(): void
    {
        // Receive into the higher-ID location first, then the default shop for the same lot.
        $goods = $this->fifoGoods(quantity: '3.000', received: false);
        $other = $this->fifoLocation();
        $original = $this->ownershipFixture['location'];
        try {
            $this->ownershipFixture['location'] = $other;
            $receipt = $this->receivePurchase($goods['purchase'], '2.000');
        } finally {
            $this->ownershipFixture['location'] = $original;
        }
        $this->linkReceipt($goods['lot'], $receipt, '2.000');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000');
        $this->linkReceipt($goods['lot'], $receipt, '1.000');
        $atOther = array_replace($goods, ['location' => $other]);
        $this->assertFifo($goods, '2.000', [$this->fifoRow($goods, '1.000'), $this->fifoRow($atOther, '1.000')]);
    }

    public function test_unreceived_and_unlinked_lots_cannot_borrow_another_lots_stock(): void
    {
        // Six older owned units are not eligible; only the newer three are actually received and linked.
        $waiting = $this->fifoGoods(received: false, date: '2026-10-01');
        $this->fifoGoods($waiting['item'], date: '2026-10-02', linked: false);
        $ready = $this->fifoGoods($waiting['item']);
        $this->assertFifo($ready, '3.000', [$this->fifoRow($ready, '3.000')]);
        $this->rejectFifo($ready['item']->code, '3.001', [], 'ownership.insufficient_lot_balance');
    }

    public function test_reserved_and_pledged_quantities_are_excluded(): void
    {
        // Three owned/received units leave only one eligible after a sale reservation and a pledge.
        $goods = $this->fifoGoods();
        $this->change($goods, 'reserve', '1.000', $this->sale($goods, '1.000'));
        $this->change($goods, 'pledge', '1.000', 'FIFO-PLEDGE');
        $this->assertFifo($goods, '1.000', [$this->fifoRow($goods, '1.000')]);
        $this->rejectFifo($goods['item']->code, '1.001', [], 'ownership.insufficient_lot_balance');
    }

    public function test_non_sellable_location_is_not_available_to_generic_checkout_allocation(): void
    {
        // Restriction is fixture state, not a claim that changing a stocked location is a valid public edit.
        $goods = $this->fifoGoods();
        $goods['location']->update(['is_sellable' => false]);
        $this->rejectFifo($goods['item']->code, '1.000', [], 'ownership.insufficient_lot_balance');
    }

    public function test_inactive_location_cannot_supply_available_ownership(): void
    {
        // A stale/restricted location is ineligible even though both ledgers still hold three units.
        $goods = $this->fifoGoods();
        $goods['location']->update(['is_active' => false]);
        $this->rejectFifo($goods['item']->code, '1.000', [], 'ownership.insufficient_lot_balance');
    }

    public function test_physical_reservations_limit_allocation_even_without_ownership_reservation(): void
    {
        // Deliberately establish only the physical commitment; independent owned totals are not proof of stock.
        $goods = $this->fifoGoods();
        app(InventoryService::class)->reserveForSale(new InventoryReservationRequest(
            inventoryItemCode: $goods['item']->code, locationCode: $goods['location']->code,
            quantity: '2.000', sourceType: 'SaleOrderLine', sourceCode: $this->sale($goods, '2.000'),
        ));
        $this->assertFifo($goods, '1.000', [$this->fifoRow($goods, '1.000')]);
        $this->rejectFifo($goods['item']->code, '1.001', [], 'ownership.insufficient_lot_balance');
    }

    public function test_serial_selection_uses_actual_newer_lot_without_substitution(): void
    {
        // The older serial stays untouched: caller selection, not lot age, identifies the physical piece.
        $old = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-OLD']);
        $new = $this->fifoGoods($old['item'], '1.000', '2026-10-12', ['FIFO-NEW']);
        $this->assertFifo($old, '1.000', [$this->fifoRow($new, '1.000')], ['FIFO-NEW']);
    }

    public function test_selected_serials_group_by_actual_lot_and_location(): void
    {
        // Reverse caller order cannot detach pieces from their actual current lot/location memberships.
        $first = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-A']);
        $second = $this->fifoGoods($first['item'], '1.000', '2026-10-12', ['FIFO-B'], location: $this->fifoLocation());
        $this->assertFifo($first, '2.000', [$this->fifoRow($first, '1.000'), $this->fifoRow($second, '1.000')], ['FIFO-B', 'FIFO-A']);
    }

    public function test_same_lot_selected_pieces_use_stable_piece_id_order(): void
    {
        // Identifier order differs from insertion order; output must carry physical public codes.
        $goods = $this->fifoGoods(quantity: '2.000', serials: ['FIFO-Z', 'FIFO-A']);
        $this->assertFifo($goods, '2.000', [$this->fifoRow($goods, '2.000')], ['FIFO-A', 'FIFO-Z']);
    }

    public function test_unique_item_allocates_exactly_one_without_serial_input(): void
    {
        // UNIQUE is one physical item, not a SERIALIZED item requiring a serial-number list.
        $goods = $this->fifoGoods($this->createItem('UNIQUE'), '1.000');
        $this->assertFifo($goods, '1.000', [$this->fifoRow($goods, '1.000')]);
        $this->rejectFifo($goods['item']->code, '2.000', [], 'inventory.unique_quantity');
    }

    public function test_cost_and_currency_snapshots_remain_separate(): void
    {
        // Baseline lot snapshots differ; allocation must neither average cost nor convert currencies.
        $first = $this->fifoGoods(quantity: '1.000');
        $currency = Currency::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'scope_key' => 'tenant:'.$this->ownershipFixture['tenant']->id, 'code' => 'USD',
            'name' => 'FIFO dollar', 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP', 'is_active' => true]);
        $second = $this->fifoGoods($first['item'], '1.000', '2026-10-12');
        $first['lot']->update(['unit_cost_basis' => '12.3456']);
        $second['lot']->update(['unit_cost_basis' => '4.5678', 'currency_code' => $currency->code]);
        $this->assertFifo($first, '2.000', [$this->fifoRow($first, '1.000'), $this->fifoRow($second, '1.000')]);
    }

    public function test_same_serial_in_another_tenant_does_not_make_local_selection_ambiguous(): void
    {
        // Tenant/identifier uniqueness prevents local ambiguity; the same serial is valid in another tenant.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-SHARED']);
        $home = $this->ownershipFixture;
        $this->createTenant();
        $this->fifoGoods(quantity: '1.000', serials: ['FIFO-SHARED']);
        $this->setTenant($home);
        $this->assertFifo($goods, '1.000', [$this->fifoRow($goods, '1.000')], ['FIFO-SHARED']);
    }
}
