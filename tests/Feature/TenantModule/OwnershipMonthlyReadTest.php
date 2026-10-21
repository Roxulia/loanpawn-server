<?php

namespace Tests\Feature\TenantModule;

use App\Services\TenantModule\OwnershipService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipMonthlyTestCase;

class OwnershipMonthlyReadTest extends OwnershipMonthlyTestCase
{
    public function test_timezone_cutoff_stays_pinned_after_timezone_change(): void
    {
        $goods = $this->linkedGoods();
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        DB::table('tenant_settings')->where('tenant_id', $tenant)->where('key', 'timezone')->update(['value' => 'Asia/Yangon']);
        $this->clock('2026-11-15 12:00:00');
        $original = app(TenantContext::class);
        $original->set(999999999);
        $this->summarize();
        $this->assertSame($original, app(TenantContext::class));
        $this->assertSame(999999999, app(TenantContext::class)->id());
        $this->setTenant($this->ownershipFixture);
        $header = $this->checkpoint($goods);
        $this->assertSame('2026-10-31 17:30:00', $header->cutoff_at);
        $this->assertSame('Asia/Yangon', $header->timezone);
        DB::table('tenant_settings')->where('tenant_id', $tenant)->where('key', 'timezone')->update(['value' => 'America/Los_Angeles']);
        $this->assertSame(0, $this->summarize());
        $this->assertEquals($header, $this->checkpoint($goods));
    }

    public function test_new_acquisition_after_checkpoint_uses_new_lot_and_existing_base(): void
    {
        $goods = $this->linkedGoods();
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        // A second purchase reuses identity but has no receipt. It must add ownership without
        // replacing the checkpointed first lot or borrowing its received-backed capacity.
        $purchase = $this->paidPurchase($goods['item'], '2.000', '24.68');
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $second = ['purchase' => $purchase, 'item' => $goods['item'], 'owned' => $goods['owned'], 'lot' => $lot];
        $this->assertLotBalance($goods, '10.000');
        $this->assertLotBalance($second, '2.000');
        $this->assertSame('2.000', $this->lotData($second)['awaiting_receipt_quantity']);
        $this->assertSame('0.000', $this->lotData($second)['received_backed_quantity']);
    }

    public function test_cutoff_uses_recording_time_instead_of_backdated_occurrence(): void
    {
        // Two October-dated actions are recorded on opposite sides of Yangon midnight. Only
        // the earlier recorded delta belongs in October, even if both business dates say October.
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        DB::table('tenant_settings')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('key', 'timezone')->update(['value' => 'Asia/Yangon']);
        $this->clock('2026-10-31 17:29:59');
        $this->change($goods, 'reserve', '2.000', $source);
        $this->clock('2026-10-31 17:30:00');
        $this->change($goods, 'reserve', '1.000', $source);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $header = $this->checkpoint($goods);
        $row = DB::table('ownership_monthly_checkpoint_balances')->where('ownership_monthly_checkpoint_id', $header->id)->firstOrFail();
        $this->assertSame('2.000', $row->reserved_quantity);
        $this->assertLotBalance($goods, '10.000', '3.000');
    }

    public function test_live_and_incremental_queries_exclude_checkpoint_covered_rows(): void
    {
        $goods = $this->linkedGoods();
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $source = $this->sale($goods);
        $this->change($goods, 'reserve', '1.000', $source);
        // Capture actual SQL from both paths. Functional totals alone could pass while every
        // operational read still scans the entire ledger; query-shape evidence is required too.
        $live = $this->movementSql(fn () => $this->lotData($goods));
        $this->clock('2026-12-15 12:00:00');
        $incremental = $this->movementSql(fn () => $this->summarize('2026-11'));
        foreach ([$live, $incremental] as $queries) {
            $this->assertNotEmpty($queries);
            foreach ($queries as $sql) {
                $this->assertStringContainsString('ownership_monthly_checkpoints', $sql);
                $this->assertStringContainsString('movement_watermark', $sql);
                $this->assertStringContainsString('created_at', $sql);
                $this->assertMatchesRegularExpression('/(>|not\s+exists)/i', $sql);
            }
        }
        $this->assertLotBalance($goods, '10.000', '1.000');
    }

    public function test_missing_legacy_recording_time_stays_in_live_tail(): void
    {
        // Deliberately simulate one historical row with unknown recording time. It cannot safely
        // belong to any closed month, but its ownership must remain visible exactly once today.
        $goods = $this->goods();
        DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $goods['lot']->id)->update(['created_at' => null]);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $header = $this->checkpoint($goods);
        $this->assertSame('0.000', $header->owned_quantity);
        $this->assertLotBalance($goods, '10.000');
        $this->clock('2026-12-15 12:00:00');
        $this->summarize('2026-11');
        $this->assertLotBalance($goods, '10.000');
    }

    private function movementSql(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $action();
            return array_values(array_map(fn ($entry) => strtolower($entry['query']), array_filter(DB::getQueryLog(),
                fn ($entry) => str_starts_with(strtolower(ltrim($entry['query'])), 'select')
                    && str_contains($entry['query'], 'ownership_movements')
                    && str_contains(strtolower($entry['query']), 'sum('))));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
