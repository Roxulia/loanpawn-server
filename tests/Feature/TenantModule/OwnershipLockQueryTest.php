<?php

namespace Tests\Feature\TenantModule;

use App\Repository\OwnershipRepository;
use App\Services\TenantModule\Ownership\LockService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipFifoTestCase;

/** OWN-19 repository contracts; actual contention remains the independent-worker suites. */
class OwnershipLockQueryTest extends OwnershipFifoTestCase
{
    public function test_complete_item_set_is_locked_before_aggregates_and_lots(): void
    {
        // Reverse the input and repeat one item; locks must follow stored IDs, without changing quantities.
        $first = $this->fifoGoods(received: false);
        $second = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $owners = app(LockService::class)->items([$second['item']->code, $first['item']->code, $first['item']->code]);
            $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower($sql));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([$first['item']->id, $second['item']->id], $owners->keys()->all());
        $locks = $queries->filter(fn ($sql) => str_contains($sql, 'for update'))->values();
        $this->assertStringContainsString('inventory_items', $locks[0]);
        $this->assertStringContainsString('owned_items', $locks[1]);
        $this->assertStringContainsString('acquisition_lots', $locks[2]);
        $this->assertSame($before, $this->fifoState());
    }

    public function test_missing_first_aggregate_uses_inventory_anchor_without_creating_ownership(): void
    {
        // A first acquisition can lock an identity even though there is no Ownership row to lock yet.
        $item = $this->createItem();
        $this->assertTrue(app(LockService::class)->items([$item->code])->isEmpty());
        $this->assertNull(app(OwnershipRepository::class)->ownedItemForInventory(
            (int) $this->ownershipFixture['tenant']->id, (int) $item->id));
    }

    public function test_summary_anchor_and_append_anchor_resolve_the_same_tenant_row(): void
    {
        // Publication uses the same aggregate row as append; a foreign tenant cannot acquire it.
        $goods = $this->fifoGoods(received: false);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $this->assertSame($goods['owned']->id, $repository->lockAggregate($tenant, $goods['owned']->id)->id);
        $this->createTenant();
        $this->assertNull($repository->lockAggregate((int) $this->ownershipFixture['tenant']->id, $goods['owned']->id));
    }

    public function test_locked_checkpoint_children_cannot_supply_another_items_lot(): void
    {
        // A malformed historical child references a real same-tenant lot belonging to another item.
        $goods = $this->fifoGoods(received: false);
        $other = $this->fifoGoods(received: false);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $header = DB::table('ownership_monthly_checkpoints')->insertGetId([
            'tenant_id' => $tenant, 'owned_item_id' => $goods['owned']->id, 'month' => '2026-09-01',
            'timezone' => 'UTC', 'cutoff_at' => '2026-10-01 00:00:00', 'movement_watermark' => 0,
            'completed_at' => '2026-10-02 00:00:00', 'owned_quantity' => '0.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000',
        ]);
        DB::table('ownership_monthly_checkpoint_balances')->insert([
            'tenant_id' => $tenant, 'ownership_monthly_checkpoint_id' => $header,
            'acquisition_lot_id' => $other['lot']->id, 'owned_quantity' => '999.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000',
        ]);
        [$base] = app(OwnershipRepository::class)->lockedBalanceParts($tenant, $goods['owned']->id, '2026-10-15 00:00:00');
        $this->assertTrue($base->isEmpty());
    }

    public function test_append_locks_aggregate_and_cannot_override_tenant(): void
    {
        // Supply another tenant in the payload: the method's authoritative tenant still owns the append.
        $goods = $this->fifoGoods(received: false);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $data = DB::table('ownership_movements')->where('owned_item_id', $goods['owned']->id)->first();
        $data = (array) $data;
        unset($data['id']);
        $data['code'] = 'LOCK'.bin2hex(random_bytes(8));
        $data['tenant_id'] = 0;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $movement = app(OwnershipRepository::class)->createMovement($tenant, $data);
            $queries = collect(DB::getQueryLog())->pluck('query')->values();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($tenant, (int) $movement->tenant_id);
        $this->assertStringContainsString('owned_items', strtolower($queries[0]));
        $this->assertStringContainsString('for update', strtolower($queries[0]));
        $this->assertStringContainsString('insert', strtolower($queries[1]));
    }
}
