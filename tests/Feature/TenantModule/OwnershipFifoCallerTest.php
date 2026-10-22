<?php

namespace Tests\Feature\TenantModule;

use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\{CommittedOwnershipFifoTestCase, OwnershipFifoCaller};

/** Atomic caller effects are distinct from the allocator's read-only selection responsibility. */
class OwnershipFifoCallerTest extends CommittedOwnershipFifoTestCase
{
    public function test_caller_applies_exact_allocations_to_both_ledgers(): void
    {
        // From old=3/new=5, selling six must leave old=0/new=2 and item/physical total two.
        $old = $this->fifoGoods();
        $new = $this->fifoGoods($old['item'], '5.000', '2026-10-12');
        $sources = $this->fifoSources([$old, $new], '6.000');
        DB::transaction(function () use ($old, $sources): void {
            OwnershipFifoCaller::lockSources($sources);
            OwnershipFifoCaller::apply($this->fifo($old['item']->code, '6.000'), $sources);
        });
        $this->assertLotBalance($old, '0.000');
        $this->assertLotBalance($new, '2.000');
        $this->assertBalances((int) $old['owned']->id, '2.000');
        $this->assertSame('2.000', app(\App\Services\TenantModule\InventoryService::class)->itemDetail($old['item']->code)->totalQuantity);

        // A depleted old lot cannot reappear in the next allocation.
        $this->assertFifo($old, '2.000', [$this->fifoRow($new, '2.000')]);
    }

    public function test_serial_caller_issues_only_the_explicitly_selected_piece(): void
    {
        // Selected newer serial leaves the older serial physically present and currently owned.
        $old = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-KEEP']);
        $new = $this->fifoGoods($old['item'], '1.000', '2026-10-12', ['FIFO-SELL']);
        $sources = $this->fifoSources([$new], '1.000');
        DB::transaction(function () use ($old, $sources): void {
            OwnershipFifoCaller::lockSources($sources);
            OwnershipFifoCaller::apply($this->fifo($old['item']->code, '1.000', ['FIFO-SELL']), $sources);
        });
        $this->assertCurrentPieces($old, $old['pieces']);
        $this->assertCurrentPieces($new, []);
        $this->assertLotBalance($new, '0.000');
        $this->assertFifoBalances($old, '1.000');
        $this->assertFifo($old, '1.000', [$this->fifoRow($old, '1.000')], ['FIFO-KEEP']);
    }

    public function test_failure_after_first_applied_lot_rolls_back_every_effect(): void
    {
        // Failure follows real consumption and issue of the first lot, before applying the second.
        $old = $this->fifoGoods();
        $new = $this->fifoGoods($old['item'], date: '2026-10-12');
        $sources = $this->fifoSources([$old, $new], '4.000');
        $before = $this->receiptState();
        try {
            DB::transaction(function () use ($old, $sources): void {
                OwnershipFifoCaller::lockSources($sources);
                OwnershipFifoCaller::apply($this->fifo($old['item']->code, '4.000'), $sources, failAfter: 1);
            });
            $this->fail('The injected caller failure must occur after the first actual issue.');
        } catch (RuntimeException $error) {
            $this->assertSame('FIFO caller failed after applying allocation.', $error->getMessage());
        }
        $this->assertSame($before, $this->receiptState());
        $this->assertFifo($old, '4.000', [$this->fifoRow($old, '3.000'), $this->fifoRow($new, '1.000')]);
    }

    public function test_allocation_requires_an_existing_caller_transaction(): void
    {
        // Committed fixtures ensure the usual rollback fixture does not accidentally satisfy this guard.
        $goods = $this->fifoGoods();
        $before = $this->receiptState();
        $this->assertSame(0, DB::connection()->transactionLevel());
        try {
            $this->fifo($goods['item']->code, '1.000');
            $this->fail('FIFO must not return an allocation after releasing its own transaction locks.');
        } catch (LogicException $error) {
            $this->assertSame('Ownership FIFO allocation requires an active transaction.', $error->getMessage());
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame($before, $this->receiptState());
    }

    public function test_checkpoint_plus_tail_matches_full_ledger_allocation(): void
    {
        // Independent known October closings: old=1/new=2. November then reserves one newer unit.
        $old = $this->fifoGoods(quantity: '1.000');
        $new = $this->fifoGoods($old['item'], '2.000', '2026-10-12');
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $watermark = DB::table('ownership_movements')->where('tenant_id', $tenant)->max('id');
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));
        $this->change($new, 'reserve', '1.000', $this->sale($new, '1.000'));
        $expected = [$this->fifoRow($old, '1.000'), $this->fifoRow($new, '1.000')];
        $withoutCheckpoint = $this->assertFifo($old, '2.000', $expected);
        $this->seedClosing([$old, $new], $watermark);
        $this->assertSame($withoutCheckpoint, $this->assertFifo($old, '2.000', $expected));

        // The oracle sums actual movements independently, including the newer reservation.
        foreach ([[$old, '1.000'], [$new, '1.000']] as [$goods, $available]) {
            $actual = DB::table('ownership_movements')->where('tenant_id', $tenant)
                ->where('acquisition_lot_id', $goods['lot']->id)
                ->selectRaw('CAST(SUM(owned_delta - reserved_delta - pledged_delta) AS DECIMAL(24,3)) AS available')->first();
            $this->assertSame($available, $actual->available);
        }
    }

    private function seedClosing(array $goods, int $watermark): void
    {
        // Seed literal read-side facts only: this is not evidence of monthly publication correctness.
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $header = DB::table('ownership_monthly_checkpoints')->insertGetId([
            'tenant_id' => $tenant, 'owned_item_id' => $goods[0]['owned']->id, 'month' => '2026-10-01',
            'timezone' => 'UTC', 'cutoff_at' => '2026-11-01 00:00:00', 'movement_watermark' => $watermark,
            'completed_at' => '2026-11-02 00:00:00', 'owned_quantity' => '3.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000']);
        foreach ($goods as $row) {
            DB::table('ownership_monthly_checkpoint_balances')->insert(['tenant_id' => $tenant,
                'ownership_monthly_checkpoint_id' => $header, 'acquisition_lot_id' => $row['lot']->id,
                'owned_quantity' => $row['lot']->acquired_quantity,
                'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000']);
        }
    }
}
