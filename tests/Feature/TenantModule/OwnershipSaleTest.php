<?php

namespace Tests\Feature\TenantModule;

use App\Services\TenantModule\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipQuantityTestCase;

/** OWN-08a-d: source-bound reservations and consumption, without sale financial orchestration. */
class OwnershipSaleTest extends OwnershipQuantityTestCase
{
    public function test_released_capacity_can_be_reserved_again_without_reusing_consumed_quantity(): void
    {
        // Releasing five restores the line allowance. Consuming two permanently uses two of its five units.
        $goods = $this->linkedGoods();
        $source = $this->sale($goods, '5.000');
        $this->change($goods, 'reserve', '5.000', $source);
        $this->change($goods, 'release', '5.000', $source);
        $this->change($goods, 'reserve', '5.000', $source);
        $this->change($goods, 'consume', '2.000', $source);
        $this->change($goods, 'release', '3.000', $source);
        $this->assertNoChange(fn () => $this->change($goods, 'reserve', '3.001', $source));
        $this->change($goods, 'reserve', '3.000', $source);
        $this->assertLotBalance($goods, '8.000', '3.000');
    }

    public function test_exact_reservation_and_one_thousandth_excess(): void
    {
        // Four reserved units leave exactly six; a new source cannot take 6.001.
        $goods = $this->linkedGoods();
        $first = $this->sale($goods);
        $second = $this->sale($goods);
        $this->change($goods, 'reserve', '4.000', $first);
        $this->assertLotBalance($goods, '10.000', '4.000');
        $this->assertNoChange(fn () => $this->change($goods, 'reserve', '6.001', $second));
        $this->change($goods, 'reserve', '6.000', $second);
        $this->assertLotBalance($goods, '10.000', '10.000');
        $this->assertSame('10.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
    }

    public function test_release_uses_only_its_own_remaining_commitment(): void
    {
        // S1 owns four of seven reserved units. S2's three cannot cover an excess S1 release.
        $goods = $this->linkedGoods();
        $first = $this->sale($goods);
        $second = $this->sale($goods);
        $unused = $this->sale($goods);
        $this->change($goods, 'reserve', '4.000', $first);
        $this->change($goods, 'reserve', '3.000', $second);
        $this->change($goods, 'release', '1.000', $first);
        $this->assertLotBalance($goods, '10.000', '6.000');
        $this->assertNoChange(fn () => $this->change($goods, 'release', '3.001', $first));
        $this->assertNoChange(fn () => $this->change($goods, 'release', '1.000', $unused));
        $this->change($goods, 'release', '3.000', $first);
        $this->assertNoChange(fn () => $this->change($goods, 'release', '0.001', $first));
        $this->assertLotBalance($goods, '10.000', '3.000');
    }

    public function test_consumption_exhausts_only_the_selected_sale(): void
    {
        // Consuming two twice must clear S1's four, while retaining S2's separate reservation.
        $goods = $this->linkedGoods();
        $first = $this->sale($goods);
        $second = $this->sale($goods);
        $this->change($goods, 'reserve', '4.000', $first);
        $this->change($goods, 'reserve', '1.000', $second);
        $this->change($goods, 'consume', '2.000', $first);
        $this->assertLotBalance($goods, '8.000', '3.000');
        $this->change($goods, 'consume', '2.000', $first);
        $this->assertLotBalance($goods, '6.000', '1.000');
        $this->assertSame('6.000', $this->lotData($goods)['received_backed_quantity']);
        $this->assertNoChange(fn () => $this->change($goods, 'consume', '0.001', $first));
    }

    public function test_physical_issue_and_consumption_roll_back_together(): void
    {
        // sellPiece performs real Inventory issue and Ownership consumption. The caller then fails;
        // both ledgers, source rows, commitments and current membership must return to their originals.
        $goods = $this->linkedPiece();
        $before = $this->receiptState();
        $failure = new \RuntimeException('Caller failed after physical issue');
        try {
            DB::transaction(function () use ($goods, $failure): void {
                $this->sellPiece($goods);
                throw $failure;
            });
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame($before, $this->receiptState());
        $this->assertCurrentPieces($goods, [$goods['piece']]);
    }
}
