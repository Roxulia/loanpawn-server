<?php

namespace Tests\Feature\TenantModule;

use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipMonthlyTestCase;

/** Known retained ledger facts exercise batch publication, not public/manual acquisition workflows. */
class OwnershipMonthlyBatchTest extends OwnershipMonthlyTestCase
{
    public function test_publication_crosses_lot_batch_boundary_without_repeating_full_tail(): void
    {
        // One real ten-unit acquisition plus 500 known 0.001 historical lots must close at exactly 10.500.
        $goods = $this->goods();
        $this->seedSmallHistoricalLots($goods);
        $this->clock('2026-11-15 12:00:00');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(1, $this->summarize());
            $sql = collect(DB::getQueryLog())->pluck('query')->map(fn ($query) => strtolower($query));
        } finally {
            DB::disableQueryLog();
        }
        $closingReads = $sql->filter(fn ($query) => str_starts_with($query, 'select')
            && str_contains($query, 'ownership_movements') && str_contains($query, 'sum('));
        $this->assertCount(2, $closingReads);
        $this->assertCount(2, $sql->filter(fn ($query) => str_starts_with($query, 'insert into `ownership_monthly_checkpoint_balances`')));
        $this->assertSame('10.500', $this->checkpoint($goods)->owned_quantity);
        $this->assertSame(501, DB::table('ownership_monthly_checkpoint_balances')->where('tenant_id', $this->ownershipFixture['tenant']->id)->count());
    }

    private function seedSmallHistoricalLots(array $goods): void
    {
        // Persist literal source snapshots/ledger rows in batches; do not simulate the publisher in the fixture.
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $lots = [];
        for ($i = 1; $i <= 500; $i++) {
            $lots[] = ['tenant_id' => $tenant, 'code' => 'MB'.$tenant.'-'.$i, 'owned_item_id' => $goods['owned']->id,
                'source_module' => 'OWNERSHIP', 'source_type' => 'HISTORY_FIXTURE', 'source_code' => 'H'.$i,
                'acquired_at' => '2026-10-15', 'acquired_quantity' => '0.001', 'unit_cost_basis' => '1.0000',
                'currency_code' => $goods['lot']->currency_code, 'created_at' => '2026-10-15 12:00:00', 'updated_at' => '2026-10-15 12:00:00'];
        }
        DB::table('acquisition_lots')->insert($lots);
        $rows = DB::table('acquisition_lots')->where('tenant_id', $tenant)->where('source_type', 'HISTORY_FIXTURE')->get(['id', 'code', 'source_code']);
        $movements = [];
        foreach ($rows as $lot) {
            $movements[] = ['tenant_id' => $tenant, 'code' => 'MV'.$lot->code, 'owned_item_id' => $goods['owned']->id,
                'acquisition_lot_id' => $lot->id, 'movement_type' => 'ACQUISITION', 'quantity' => '0.001',
                'owned_delta' => '0.001', 'reserved_delta' => '0.000', 'pledged_delta' => '0.000',
                'source_module' => 'OWNERSHIP', 'source_type' => 'HISTORY_FIXTURE', 'source_code' => $lot->source_code,
                'occurred_at' => '2026-10-15 12:00:00', 'created_at' => '2026-10-15 12:00:00', 'updated_at' => '2026-10-15 12:00:00'];
        }
        DB::table('ownership_movements')->insert($movements);
    }
}
