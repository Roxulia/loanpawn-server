<?php

namespace Tests\Feature\TenantModule;

use App\Models\InventoryModule\InventoryItem;
use App\Repository\OwnershipRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipMySqlTestCase;

/** Real SQL repository contracts; seeded ledger/checkpoint facts are not acquisition/publication evidence. */
class OwnershipBalanceQueryTest extends OwnershipMySqlTestCase
{
    public function test_latest_checkpoint_is_tenant_item_and_cutoff_bound(): void
    {
        // Two months exist for one item; the requested cutoff chooses the older eligible header.
        $goods = $this->baseline();
        $old = $this->checkpoint($goods, '2026-09-01', '2026-10-01 00:00:00', $goods['movement']->id);
        $latest = $this->checkpoint($goods, '2026-10-01', '2026-11-01 00:00:00', $goods['movement']->id);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $other = $this->baseline();
        $repository = app(OwnershipRepository::class);
        $rows = $repository->latestCompletedCheckpoints($tenant, [$goods['ownedItem']->id, $other['ownedItem']->id], '2026-10-15 00:00:00');
        $this->assertSame([$goods['ownedItem']->id], $rows->keys()->all());
        $this->assertSame($old, (int) $rows[$goods['ownedItem']->id]->id);
        $this->assertSame($latest, (int) $repository->latestCompletedCheckpoints($tenant, [$goods['ownedItem']->id], '2026-12-01 00:00:00')[$goods['ownedItem']->id]->id);
        $this->createTenant();
        $this->assertTrue($repository->latestCompletedCheckpoints((int) $this->ownershipFixture['tenant']->id, [$goods['ownedItem']->id], '2026-12-01 00:00:00')->isEmpty());
    }

    public function test_opening_item_and_lot_balances_come_from_the_selected_parent(): void
    {
        // Header/child quantities are exact saved facts; a missing checkpoint contributes no base row.
        $goods = $this->baseline();
        $this->checkpoint($goods, '2026-09-01', '2026-10-01 00:00:00', $goods['movement']->id);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $repository = app(OwnershipRepository::class);
        $items = $repository->openingItemBalances($tenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00');
        $lots = $repository->openingLotBalances($tenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00');
        $this->assertSame('10.000', $items[$goods['ownedItem']->id]->owned);
        $this->assertSame('10.000', $lots[$goods['lot']->id]->owned);
        $this->createTenant();
        $foreignTenant = (int) $this->ownershipFixture['tenant']->id;
        $this->assertTrue($repository->openingLotBalances($foreignTenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00')->isEmpty());
    }

    public function test_tail_keeps_low_id_new_time_high_id_backdate_and_null_recording_time_once(): void
    {
        // The base covers acquisition only. A newer-month row already exists below the watermark;
        // another row has a later ID but a backdated recording time, and legacy time is unknown.
        $goods = $this->baseline();
        DB::table('ownership_movements')->where('id', $goods['movement']->id)->update(['created_at' => '2026-09-15 00:00:00']);
        $lowId = $this->delta($goods, '2.000', '0.000', '2026-10-01 00:00:00');
        $this->checkpoint($goods, '2026-09-01', '2026-10-01 00:00:00', $lowId);
        $backdated = $this->delta($goods, '-1.000', '0.000', '2026-09-20 00:00:00');
        $legacy = $this->delta($goods, '0.000', '1.000', null);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $rows = $repository->uncoveredMovementDeltas($tenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00');
        $this->assertCount(1, $rows);
        $this->assertSame('1.000', $rows[0]->reserved);
        $this->assertSame('1.000', $rows[0]->pledged);
        $this->assertSame(['owned' => '10.000', 'reserved' => '1.000', 'pledged' => '1.000', 'available' => '8.000'],
            $repository->currentItemBalances($tenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00')[$goods['ownedItem']->id]);

        // Monthly upper bounds exclude rows at/after cutoff and leave unknown recording time live.
        $bounded = $repository->uncoveredMovementDeltas($tenant, [$goods['ownedItem']->id], '2026-11-01 00:00:00', '2026-10-01 00:00:00', $backdated);
        $this->assertSame('-1.000', $bounded[0]->reserved);
        $this->assertSame('0.000', $bounded[0]->pledged);
        $this->assertGreaterThan($backdated, $legacy);
    }

    public function test_missing_checkpoint_uses_exact_full_ledger_in_one_batched_query(): void
    {
        // Two maximum-sized lots exceed the operation parser bound when summed, without float loss.
        $goods = $this->baseline(null, '99999999999.999');
        $this->baseline($goods['ownedItem']->inventoryItem, '99999999999.999');
        $other = $this->baseline();
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $repository = app(OwnershipRepository::class);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $rows = $repository->currentItemBalances($tenant, [$goods['ownedItem']->id, $other['ownedItem']->id], '2026-11-01 00:00:00');
            $this->assertCount(1, DB::connection()->getQueryLog());
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertSame('199999999999.998', $rows[$goods['ownedItem']->id]['owned']);
        $this->assertSame('199999999999.998', $rows[$goods['ownedItem']->id]['available']);
        $this->assertSame('10.000', $rows[$other['ownedItem']->id]['owned']);
        $this->assertTrue($repository->currentItemBalances($tenant, [], '2026-11-01 00:00:00')->isEmpty());
    }

    public function test_zero_aggregate_is_retained_and_foreign_ids_are_excluded(): void
    {
        // A real item identity may have an aggregate before any movement. It must read as exact zero.
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $item = $this->createItem();
        $owned = \App\Models\OwnershipModule\OwnedItem::query()->create([
            'tenant_id' => $tenant, 'inventory_item_id' => $item->id,
            'code' => 'ZERO'.bin2hex(random_bytes(6)), 'lifecycle_status' => 'ACTIVE',
        ]);
        $this->createTenant();
        $foreign = $this->baseline();
        $rows = app(OwnershipRepository::class)->currentItemBalances($tenant, [$owned->id, $foreign['ownedItem']->id], '2026-11-01 00:00:00');
        $this->assertSame([$owned->id], $rows->keys()->all());
        $this->assertSame(['owned' => '0.000', 'reserved' => '0.000', 'pledged' => '0.000', 'available' => '0.000'], $rows[$owned->id]);
    }

    private function baseline(?InventoryItem $item = null, string $quantity = '10.000'): array
    {
        // Reuse isolated real-row fixtures without invoking the unimplemented acquisition service.
        return $this->createLot($this->createPurchase($item ?? $this->createItem(), $quantity, '0.00', null));
    }

    private function checkpoint(array $goods, string $month, string $cutoff, int $watermark): int
    {
        // Seed an independently known closing state; this tests reading, not monthly publication.
        $header = DB::table('ownership_monthly_checkpoints')->insertGetId([
            'tenant_id' => $goods['ownedItem']->tenant_id, 'owned_item_id' => $goods['ownedItem']->id,
            'month' => $month, 'timezone' => 'UTC', 'cutoff_at' => $cutoff, 'movement_watermark' => $watermark,
            'completed_at' => '2026-11-15 00:00:00', 'owned_quantity' => '10.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000',
        ]);
        DB::table('ownership_monthly_checkpoint_balances')->insert([
            'tenant_id' => $goods['ownedItem']->tenant_id, 'ownership_monthly_checkpoint_id' => $header,
            'acquisition_lot_id' => $goods['lot']->id, 'owned_quantity' => '10.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000',
        ]);

        return $header;
    }

    private function delta(array $goods, string $reserved, string $pledged, ?string $recordedAt): int
    {
        // Literal ledger deltas isolate coverage predicates from still-pending mutation orchestration.
        return DB::table('ownership_movements')->insertGetId([
            'tenant_id' => $goods['ownedItem']->tenant_id, 'owned_item_id' => $goods['ownedItem']->id,
            'acquisition_lot_id' => $goods['lot']->id, 'code' => 'Q'.bin2hex(random_bytes(8)),
            'movement_type' => 'TEST_DELTA', 'quantity' => ltrim($reserved !== '0.000' ? $reserved : $pledged, '-'), 'owned_delta' => '0.000',
            'reserved_delta' => $reserved, 'pledged_delta' => $pledged,
            'occurred_at' => '2026-09-15 00:00:00', 'created_at' => $recordedAt,
        ]);
    }
}
