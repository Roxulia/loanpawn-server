<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipListFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipLotFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipMovementFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipCandidateFilter;
use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipQuantityTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** OWN-09c-e: real paginated queries, with literal order and bounded query-count expectations. */
class OwnershipReadTest extends OwnershipQuantityTestCase
{
    public function test_pages_reach_beyond_old_caps_in_stable_order(): void
    {
        // Duplicate names deliberately require the ID tie-breaker. Default holding includes paid
        // awaiting-receipt goods, so none of these 105 aggregates may disappear from the register.
        $expected = [];
        for ($index = 0; $index < 105; $index++) {
            $goods = $this->goods('QUANTITY', '1.000', '12.34');
            $goods['item']->update(['name' => 'Same name']);
            $expected[] = $goods['owned']->code;
        }
        $service = app(OwnershipService::class);
        $first = $service->itemPage(OwnershipListFilter::fromValidated(['per_page' => 100]))->toArray();
        $last = $service->itemPage(OwnershipListFilter::fromValidated(['per_page' => 100, 'page' => 2]))->toArray();
        $this->assertSame(105, $first['total']);
        $this->assertSame($expected, array_merge(array_column($first['items'], 'code'), array_column($last['items'], 'code')));
        $this->assertCount(5, $last['items']);
        $this->assertSame('1.000', $first['items'][0]['awaiting_receipt_quantity']);
    }

    public function test_holding_and_search_use_current_balances(): void
    {
        // A disposed serialized item remains searchable history; an awaiting-receipt item is current.
        $sold = $this->linkedPiece();
        $this->sellPiece($sold);
        $waiting = $this->goods('QUANTITY', '1.000', '12.34');
        $waiting['item']->update(['name' => 'Waiting goods']);
        $service = app(OwnershipService::class);
        $depleted = $service->itemPage(OwnershipListFilter::fromValidated(['holding' => 'depleted']))->toArray();
        $current = $service->itemPage(OwnershipListFilter::fromValidated(['holding' => 'current', 'search' => 'Waiting']))->toArray();
        $this->assertSame([$sold['owned']->code], array_column($depleted['items'], 'code'));
        $this->assertSame([$waiting['owned']->code], array_column($current['items'], 'code'));
        $this->assertSame([], $service->itemPage(OwnershipListFilter::fromValidated(['search' => 'absent name']))->toArray()['items']);
    }

    public function test_lots_and_movements_have_separate_pages(): void
    {
        $goods = $this->linkedGoods();
        $source = $this->sale($goods);
        $this->change($goods, 'reserve', '2.000', $source);
        $this->change($goods, 'release', '1.000', $source);
        $service = app(OwnershipService::class);
        $lots = $service->lotPage($goods['owned']->code, OwnershipLotFilter::fromValidated(['per_page' => 1]))->toArray();
        $this->assertSame([$goods['lot']->code], array_column($lots['items'], 'code'));
        // Frozen occurrence times tie, so recording IDs must put release before reserve and acquisition.
        $history = $service->movementPage($goods['owned']->code, OwnershipMovementFilter::fromValidated(['per_page' => 1]))->toArray();
        $this->assertCount(1, $history['items']);
        $row = $history['items'][0];
        $this->assertSame('-1.000', $row['reserved_delta']);
        $this->assertSame($source, $row['source_code']);
        $this->assertArrayHasKey('recorded_at', $row);
        $this->assertArrayNotHasKey('id', $row);
    }

    public function test_candidates_are_receipt_scoped_and_paginated(): void
    {
        // Two receipts contain different physical pieces. Selecting one receipt must never expose
        // the other's piece, even though both are the same tenant and aggregate.
        $goods = $this->goods('SERIALIZED', '2.000', '24.68');
        $first = $this->receivePurchase($goods['purchase'], '1.000', ['CANDIDATE-A']);
        $second = $this->receivePurchase($goods['purchase'], '1.000', ['CANDIDATE-B']);
        $service = app(OwnershipService::class);
        $receipts = $service->receiptLinkCandidates($goods['owned']->code, $goods['lot']->code,
            OwnershipCandidateFilter::fromValidated(['per_page' => 1, 'page' => 2]))->toArray();
        $this->assertSame(2, $receipts['total']);
        $this->assertSame($second['receiptLine']->code, $receipts['items'][0]['purchase_receipt_line_code']);
        $pieces = $service->receiptLinkCandidates($goods['owned']->code, $goods['lot']->code,
            OwnershipCandidateFilter::fromValidated(['purchase_receipt_line_code' => $first['receiptLine']->code]))->toArray();
        $this->assertSame($first['inventory']['serialized_item_codes'], array_column($pieces['items'], 'code'));
    }

    public function test_summary_queries_are_batched_and_serialization_is_query_free(): void
    {
        // Warm the identical service path before measuring. Twenty-five rows should use a fixed
        // batch of queries, not twenty-five additional per-item balance/photo/lot fetches.
        $this->goods();
        $service = app(OwnershipService::class);
        $service->itemPage(OwnershipListFilter::fromValidated([]))->toArray();
        $one = $this->queries(fn () => $service->itemPage(OwnershipListFilter::fromValidated([])));
        for ($index = 1; $index < 25; $index++) $this->goods();
        $many = $this->queries(fn () => $service->itemPage(OwnershipListFilter::fromValidated([])));
        $this->assertLessThanOrEqual($one + 2, $many);
        $page = $service->itemPage(OwnershipListFilter::fromValidated([]));
        $this->assertSame(0, $this->queries(function () use ($page): void {
            foreach ($page->toArray()['items'] as $row) {
                $this->assertArrayNotHasKey('lots', $row);
                $this->assertArrayNotHasKey('serialized_items', $row);
            }
        }));
    }

    public function test_many_lots_remain_paginated_without_summary_expansion(): void
    {
        $goods = $this->goods();
        $expected = [$goods['lot']->code];
        for ($index = 1; $index < 26; $index++) {
            $purchase = $this->paidPurchase($goods['item']);
            $expected[] = $this->assertAcquisition($purchase, $this->acquire($purchase))->code;
        }
        $service = app(OwnershipService::class);
        $first = $service->lotPage($goods['owned']->code, OwnershipLotFilter::fromValidated([]))->toArray();
        $last = $service->lotPage($goods['owned']->code, OwnershipLotFilter::fromValidated(['page' => 2]))->toArray();
        $this->assertSame($expected, array_merge(array_column($first['items'], 'code'), array_column($last['items'], 'code')));
        $this->assertSame(26, $first['total']);
        $summary = $service->itemPage(OwnershipListFilter::fromValidated([]))->toArray()['items'][0];
        $this->assertSame(26, $summary['lot_count']);
        $this->assertArrayNotHasKey('lots', $summary);
        $this->assertSame([], $service->lotPage($goods['owned']->code,
            OwnershipLotFilter::fromValidated(['currency_code' => 'USD']))->toArray()['items']);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_reject_before_read(string $class, array $input): void
    {
        // Factories are also called from HTTP validation; invalid enums and unbounded searches
        // must reject, rather than silently broaden the query or truncate user input.
        $this->expectException(\App\Exceptions\InvalidTenantRequest::class);
        $class::fromValidated($input);
    }

    public static function invalidFilters(): array
    {
        return ['holding' => [OwnershipListFilter::class, ['holding' => 'unknown']],
            'tracking' => [OwnershipListFilter::class, ['tracking_mode' => 'unknown']],
            'search' => [OwnershipListFilter::class, ['search' => str_repeat('a', 121)]],
            'lot holding' => [OwnershipLotFilter::class, ['holding' => 'unknown']],
            'candidate search' => [OwnershipCandidateFilter::class, ['search' => str_repeat('a', 121)]],
            'history page' => [OwnershipMovementFilter::class, ['page' => 0]]];
    }

    private function queries(callable $action): int
    {
        // Stop logging in finally so an assertion or service error cannot leak logging into later cases.
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $action();
            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
