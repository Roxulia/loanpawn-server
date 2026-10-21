<?php

namespace Tests\Feature\TenantModule;

use App\Services\TenantModule\InventoryService;
use Tests\Support\OwnershipQuantityTestCase;

/** OWN-08e-g: quantity primitives only; no claim about lender custody or loan payment. */
class OwnershipPledgeTest extends OwnershipQuantityTestCase
{
    public function test_sale_and_pledge_share_available_quantity(): void
    {
        // Four reserved for sale leave six for collateral; ownership and physical stock stay ten.
        $goods = $this->linkedGoods();
        $sale = $this->sale($goods);
        $this->change($goods, 'reserve', '4.000', $sale);
        $other = $this->otherState();
        $this->assertNoChange(fn () => $this->change($goods, 'pledge', '6.001', 'L1'));
        $this->change($goods, 'pledge', '6.000', 'L1');
        $this->assertLotBalance($goods, '10.000', '4.000', '6.000');
        $this->assertSame($other, $this->otherState());
        $this->assertSame('10.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
    }

    public function test_release_cannot_borrow_another_loan_commitment(): void
    {
        // L1 has three remaining after partial release. L2's three must remain untouched.
        $goods = $this->linkedGoods();
        $this->change($goods, 'pledge', '4.000', 'L1');
        $this->change($goods, 'pledge', '3.000', 'L2');
        $this->change($goods, 'unpledge', '1.000', 'L1');
        $this->assertLotBalance($goods, '10.000', '0.000', '6.000');
        $this->assertNoChange(fn () => $this->change($goods, 'unpledge', '3.001', 'L1'));
        $this->assertNoChange(fn () => $this->change($goods, 'unpledge', '1.000', 'L3'));
        $this->change($goods, 'unpledge', '3.000', 'L1');
        $this->assertNoChange(fn () => $this->change($goods, 'unpledge', '0.001', 'L1'));
        $this->assertLotBalance($goods, '10.000', '0.000', '3.000');
    }

    public function test_fully_pledged_lot_can_be_forfeited(): void
    {
        // Free availability is zero, but all ten belong to L1's pledge and may be forfeited together.
        $goods = $this->linkedGoods();
        $lots = $this->lotSnapshots();
        $this->change($goods, 'pledge', '10.000', 'L1');
        $other = $this->otherState();
        $this->assertNoChange(fn () => $this->change($goods, 'forfeit', '1.000', 'L2'));
        $this->assertNoChange(fn () => $this->change($goods, 'forfeit', '10.001', 'L1'));
        $this->change($goods, 'forfeit', '10.000', 'L1');
        $this->assertLotBalance($goods, '0.000');
        $this->assertSame($lots, $this->lotSnapshots());
        $this->assertSame($other, $this->otherState());
    }

    public function test_wrong_piece_cannot_release_or_forfeit_a_pledge(): void
    {
        // Both pieces are valid current memberships, but only A belongs to this collateral source.
        $first = $this->linkedPiece('PLEDGE-A');
        $second = $this->linkedPiece('PLEDGE-B');
        $this->change($first, 'pledge', '1.000', 'L1', [$first['piece']]);
        foreach (['unpledge', 'forfeit'] as $action) {
            $this->assertNoChange(fn () => $this->change($first, $action, '1.000', 'L1', [$second['piece']]));
        }
        $this->change($first, 'forfeit', '1.000', 'L1', [$first['piece']]);
        $this->assertCurrentPieces($first, []);
        $this->assertCurrentPieces($second, [$second['piece']]);
    }
}
