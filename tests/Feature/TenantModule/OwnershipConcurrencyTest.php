<?php

namespace Tests\Feature\TenantModule;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipMonthlyTestCase;

/** OWN-10d-g/11h-i: guarded committed fixtures, independent processes and observed lock waits. */
class OwnershipConcurrencyTest extends OwnershipMonthlyTestCase
{
    public function test_one_purchase_source_cannot_be_acquired_twice(): void
    {
        $purchase = $this->paidPurchase($this->createItem(), '1.000', '12.34');
        $payload = ['purchase_orders' => [$purchase['order']->code], 'items' => [$purchase['item']->code],
            'purchase_line' => $purchase['line']->code];
        $this->race('acquire', $payload + ['key' => 'acquire-first'], 'acquire', $payload + ['key' => 'acquire-second'], 'rejected');
        $this->assertSame(1, DB::table('acquisition_lots')->where('tenant_id', $this->ownershipFixture['tenant']->id)->count());
        $this->assertSame(1, DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)->count());
    }

    public function test_one_piece_has_only_one_current_link(): void
    {
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['RACE-A']);
        $payload = $this->references($goods) + ['receipt_line' => $receipt['receiptLine']->code, 'quantity' => '1.000',
            'codes' => $receipt['inventory']['serialized_item_codes'], 'update_key' => (int) $goods['owned']->fresh()->update_key];
        $this->race('link', $payload + ['key' => 'link-first'], 'link', $payload + ['key' => 'link-second'], 'rejected');
        $this->assertCurrentPieces($goods, $receipt['inventory']['serialized_item_codes']);
        $this->assertCount(1, $this->memberships($goods));
    }

    #[DataProvider('trackingModes')]
    public function test_sale_and_pledge_cannot_both_take_last_unit(bool $serialized): void
    {
        $goods = $serialized ? $this->linkedPiece() : $this->linkedGoods();
        $quantity = $serialized ? '1.000' : '10.000';
        $sale = $this->sale($goods, $quantity);
        $payload = $this->references($goods) + ['quantity' => $quantity, 'codes' => $serialized ? [$goods['piece']] : []];
        $this->race('reserve_sale', $payload + ['source_code' => $sale], 'pledge', $payload + ['source_code' => 'L1'], 'rejected');
        $this->assertLotBalance($goods, $quantity, $quantity);
    }

    public function test_reversed_item_and_lot_input_completes_without_deadlock(): void
    {
        $goods = [$this->linkedGoods(), $this->linkedGoods()];
        $sources = [];
        foreach ($goods as $row) $sources[] = ['owned' => $row['owned']->code, 'lot' => $row['lot']->code, 'sale' => $this->sale($row)];
        $payload = ['items' => array_map(fn ($row) => $row['item']->code, $goods),
            'owned_items' => array_column($sources, 'owned'), 'lots' => array_column($sources, 'lot'), 'sources' => $sources];
        $reverse = array_map('array_reverse', $payload);
        $this->race('reserve_batch', $payload, 'reserve_batch', $reverse, 'success');
        foreach ($goods as $row) $this->assertLotBalance($row, '10.000', '2.000');
    }

    public function test_sale_waits_for_link_then_sees_committed_membership(): void
    {
        // The contender begins with an unlinked piece. After waiting it must re-read membership
        // under the lock and succeed, rather than reject from a stale pre-lock eligibility snapshot.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['RACE-LINK']);
        $source = $this->sale($goods, '1.000');
        $payload = $this->references($goods) + ['quantity' => '1.000', 'codes' => $receipt['inventory']['serialized_item_codes']];
        $this->race('link', $payload + ['receipt_line' => $receipt['receiptLine']->code,
            'update_key' => (int) $goods['owned']->fresh()->update_key, 'key' => 'link-before-sale'],
            'reserve_sale', $payload + ['source_code' => $source], 'success');
        $this->assertLotBalance($goods, '1.000', '1.000');
    }

    #[DataProvider('summaryDirections')]
    public function test_append_and_summary_keep_each_delta_once(bool $summaryFirst): void
    {
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $append = $this->references($goods) + ['source_code' => $source, 'quantity' => '2.000', 'now' => '2026-11-15 12:00:00'];
        $summary = ['month' => '2026-10', 'now' => '2026-11-15 12:00:00'];
        $this->race($summaryFirst ? 'summary' : 'reserve_sale', $summaryFirst ? $summary : $append,
            $summaryFirst ? 'reserve_sale' : 'summary', $summaryFirst ? $append : $summary, 'success');
        $this->assertLotBalance($goods, '10.000', '2.000');
        $this->assertCount(1, $this->checkpoints()['ownership_monthly_checkpoints']);
    }

    public function test_competing_summaries_publish_only_one_header(): void
    {
        $goods = $this->linkedGoods();
        $payload = ['month' => '2026-10', 'now' => '2026-11-15 12:00:00'];
        $this->race('summary', $payload, 'summary', $payload, 'success');
        $this->assertCount(1, $this->checkpoints()['ownership_monthly_checkpoints']);
        $this->assertCount(1, $this->checkpoints()['ownership_monthly_checkpoint_balances']);
        $this->assertLotBalance($goods, '10.000');
    }

    public static function trackingModes(): array
    {
        return ['quantity' => [false], 'piece' => [true]];
    }

    public static function summaryDirections(): array
    {
        return ['append holds first' => [false], 'summary holds first' => [true]];
    }

    private function references(array $goods): array
    {
        return ['items' => [$goods['item']->code], 'owned_item_code' => $goods['owned']->code, 'lot_code' => $goods['lot']->code];
    }

    private function race(string $firstAction, array $firstPayload, string $secondAction, array $secondPayload, string $expected): void
    {
        // The worker timeout bounds both processes. An observed MySQL wait, not elapsed time,
        // establishes overlap; teardown stops all workers before deleting exact fixture tenants.
        [$first, $input] = $this->worker()->start($firstAction, $firstPayload + ['wait' => true]);
        $this->worker()->waitForState($first, 'locked');
        [$second] = $this->worker()->start($secondAction, $secondPayload + ['wait' => false]);
        $this->worker()->waitForLock($second);
        $this->worker()->release($input);
        $first->wait();
        $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state']);
        $result = $this->worker()->lastMessage($second);
        $this->assertSame($expected, $result['state']);
        if ($expected === 'rejected') {
            $this->assertContains($result['exception'], [\App\Exceptions\InvalidTenantRequest::class,
                \App\Exceptions\AlreadyUpdatedException::class]);
        }
    }
}
