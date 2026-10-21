<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Services\TenantModule\OwnershipMonthlySummaryService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipMonthlyTestCase;

class OwnershipMonthlyTest extends OwnershipMonthlyTestCase
{
    public function test_first_checkpoint_preserves_waiting_and_zero_lots(): void
    {
        // October has received stock, paid-but-unreceived ownership, and a completely sold lot.
        // Every lot needs a closing row, including zero, so older balances cannot reappear later.
        $received = $this->linkedGoods();
        $waiting = $this->goods();
        $sold = $this->linkedPiece();
        $this->sellPiece($sold);
        $source = $this->sale($received);
        $this->change($received, 'reserve', '2.000', $source);
        $this->change($received, 'pledge', '3.000', 'L1');
        $state = $this->receiptState();
        $this->clock('2026-11-15 12:00:00');
        $this->assertSame(3, $this->summarize());
        foreach ([[$received, '10.000', '2.000', '3.000'], [$waiting, '10.000', '0.000', '0.000'], [$sold, '0.000', '0.000', '0.000']] as [$goods, $owned, $reserved, $pledged]) {
            $header = $this->checkpoint($goods);
            $row = DB::table('ownership_monthly_checkpoint_balances')->where('ownership_monthly_checkpoint_id', $header->id)
                ->where('acquisition_lot_id', $goods['lot']->id)->firstOrFail();
            $this->assertSame([$owned, $reserved, $pledged], [$row->owned_quantity, $row->reserved_quantity, $row->pledged_quantity]);
            $this->assertSame([$owned, $reserved, $pledged], [$header->owned_quantity, $header->reserved_quantity, $header->pledged_quantity]);
            $this->assertLotBalance($goods, $owned, $reserved, $pledged);
        }
        $this->assertSame($state, $this->receiptState());
    }

    public function test_duplicate_run_is_unchanged_and_next_month_uses_live_tail(): void
    {
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $saved = $this->checkpoints();
        $version = $goods['owned']->fresh()->update_key;
        $this->assertSame(0, $this->summarize());
        $this->assertSame($saved, $this->checkpoints());
        $this->assertSame($version, $goods['owned']->fresh()->update_key);
        $this->change($goods, 'reserve', '4.000', $source);
        $this->clock('2026-12-15 12:00:00');
        $this->assertSame(1, $this->summarize('2026-11'));
        $this->assertLotBalance($goods, '10.000', '4.000');
        $this->assertSame($saved['ownership_monthly_checkpoints'][0], $this->checkpoints()['ownership_monthly_checkpoints'][0]);
    }

    #[DataProvider('transitions')]
    public function test_operations_after_checkpoint_match_full_ledger(string $action, string $quantity, array $expected): void
    {
        // All examples begin with sale reservation two and pledge three. A checkpoint cannot
        // freeze those commitments or make a later legal transition use stale availability.
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $this->change($goods, 'reserve', '2.000', $source);
        $this->change($goods, 'pledge', '3.000', 'L1');
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $sourceCode = $action === 'pledge' ? 'L2' : (in_array($action, ['unpledge', 'forfeit'], true) ? 'L1' : $source);
        $this->change($goods, $action, $quantity, $sourceCode);
        $this->assertLotBalance($goods, ...$expected);
    }

    public function test_watermark_does_not_hide_current_month_or_backdated_later_ids(): void
    {
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $this->clock('2026-11-02 12:00:00');
        $this->change($goods, 'reserve', '2.000', $source);
        // This November row exists below October's watermark but belongs in the live tail.
        $this->summarize();
        $watermark = $this->checkpoint($goods)->movement_watermark;
        $this->clock('2026-10-20 12:00:00');
        $this->change($goods, 'release', '1.000', $source);
        $this->assertGreaterThan($watermark, DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)->max('id'));
        $this->clock('2026-11-15 12:00:00');
        $this->assertLotBalance($goods, '10.000', '1.000');
    }

    public function test_missing_month_falls_back_to_older_checkpoint(): void
    {
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $this->change($goods, 'reserve', '4.000', $source);
        // Skip November publication entirely; December must still include all uncovered movements.
        $this->clock('2027-01-15 12:00:00');
        $this->summarize('2026-12');
        $this->assertLotBalance($goods, '10.000', '4.000');
        $this->assertSame(2, count($this->checkpoints()['ownership_monthly_checkpoints']));
    }

    #[DataProvider('invalidMonths')]
    public function test_invalid_or_incomplete_month_preserves_context(string $month): void
    {
        $this->linkedGoods();
        $context = app(TenantContext::class);
        $before = $this->checkpoints();
        try {
            $this->summarize($month);
            $this->fail('Invalid or incomplete month must reject.');
        } catch (InvalidTenantRequest $error) {
            $this->assertSame(422, $error->statusCode());
        }
        $this->assertSame($context, app(TenantContext::class));
        $this->assertSame($before, $this->checkpoints());
    }

    public function test_checkpoint_does_not_authorize_another_sources_release(): void
    {
        $goods = $this->linkedPiece();
        $source = $this->sale($goods, '1.000');
        $wrong = $this->sale($goods, '1.000');
        $this->change($goods, 'reserve', '1.000', $source, [$goods['piece']]);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $this->assertNoChange(fn () => $this->change($goods, 'release', '1.000', $wrong, [$goods['piece']]));
        $this->assertCurrentPieces($goods, [$goods['piece']]);
        $this->change($goods, 'release', '1.000', $source, [$goods['piece']]);
        $this->assertLotBalance($goods, '1.000');
    }

    public function test_failed_item_publication_is_atomic_and_retry_resumes(): void
    {
        $this->linkedGoods();
        $this->linkedGoods();
        $this->clock('2026-11-15 12:00:00');
        $armed = true;
        $headers = 0;
        $failure = new \RuntimeException('Second item publication failed');
        DB::listen(function (QueryExecuted $query) use (&$armed, &$headers, $failure): void {
            if (! $armed) return;
            if (str_starts_with($query->sql, 'insert into `ownership_monthly_checkpoints`')) ++$headers;
            if ($headers === 2 && str_starts_with($query->sql, 'insert into `ownership_monthly_checkpoint_balances`')) throw $failure;
        });
        try {
            $this->summarize();
            $this->fail('Publication injection must be reached.');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        } finally {
            $armed = false;
        }
        $this->assertSame(2, $headers);
        $this->assertSame((int) $this->ownershipFixture['tenant']->id, app(TenantContext::class)->id());
        $saved = $this->checkpoints();
        $this->assertCount(1, $saved['ownership_monthly_checkpoints']);
        $this->assertCount(1, $saved['ownership_monthly_checkpoint_balances']);
        $this->assertSame(1, $this->summarize());
        $this->assertSame($saved['ownership_monthly_checkpoints'][0], $this->checkpoints()['ownership_monthly_checkpoints'][0]);
    }

    public function test_publication_rejects_an_enclosing_business_transaction(): void
    {
        // Publication owns its per-item transaction boundaries; joining a caller transaction could
        // publish partial progress or hold every item lock until unrelated business work finishes.
        $this->linkedGoods();
        $this->clock('2026-11-15 12:00:00');
        $before = $this->checkpoints();
        DB::beginTransaction();
        try {
            $this->summarize();
            $this->fail('Summary must reject an enclosing transaction.');
        } catch (\LogicException $error) {
            $this->assertSame(1, DB::connection()->transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->checkpoints());
    }

    public function test_pledge_source_remains_live_after_checkpoint(): void
    {
        $goods = $this->linkedPiece();
        $this->change($goods, 'pledge', '1.000', 'L1', [$goods['piece']]);
        $this->clock('2026-11-15 12:00:00');
        $this->summarize();
        $this->assertNoChange(fn () => $this->change($goods, 'unpledge', '1.000', 'L2', [$goods['piece']]));
        $this->change($goods, 'unpledge', '1.000', 'L1', [$goods['piece']]);
        $this->assertCurrentPieces($goods, [$goods['piece']]);
        $this->assertLotBalance($goods, '1.000');
    }

    public static function transitions(): array
    {
        return ['reserve' => ['reserve', '1.000', ['10.000', '3.000', '3.000']],
            'release' => ['release', '1.000', ['10.000', '1.000', '3.000']],
            'sale' => ['consume', '1.000', ['9.000', '1.000', '3.000']],
            'pledge' => ['pledge', '1.000', ['10.000', '2.000', '4.000']],
            'release pledge' => ['unpledge', '1.000', ['10.000', '2.000', '2.000']],
            'forfeit' => ['forfeit', '1.000', ['9.000', '2.000', '2.000']]];
    }

    public static function invalidMonths(): array
    {
        return ['invalid' => ['2026-13'], 'current' => ['2026-10'], 'future' => ['2026-11']];
    }
}
