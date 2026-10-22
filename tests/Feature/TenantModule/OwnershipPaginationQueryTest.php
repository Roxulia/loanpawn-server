<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Public\RequestObjects\{OwnershipListFilter, OwnershipLotFilter, OwnershipCandidateFilter};
use App\Exceptions\TenantNotFound;
use App\Services\TenantModule\OwnershipService;
use Tests\Support\OwnershipFifoTestCase;

/** Extra OWN-17f boundaries without depending on public endpoint/UI activation. */
class OwnershipPaginationQueryTest extends OwnershipFifoTestCase
{
    public function test_empty_and_out_of_range_pages_preserve_metadata(): void
    {
        // Empty collections have one logical page; out-of-range requests retain their requested page.
        $service = app(OwnershipService::class);
        $empty = $service->itemPage(new OwnershipListFilter())->toArray();
        $this->assertSame(['items' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0], $empty);
        $goods = $this->fifoGoods();
        $page = $service->lotPage($goods['owned']->code, new OwnershipLotFilter(page: 9, perPage: 1))->toArray();
        $this->assertSame([], $page['items']);
        $this->assertSame([9, 1, 1, 1], [$page['current_page'], $page['last_page'], $page['per_page'], $page['total']]);
    }

    public function test_tracking_filter_and_foreign_detail_keep_tenant_boundary(): void
    {
        // Both types exist locally; only the requested mode contributes to the filtered total.
        $this->fifoGoods();
        $serial = $this->fifoGoods(quantity: '1.000', serials: ['PAGING-LOCAL']);
        $service = app(OwnershipService::class);
        $page = $service->itemPage(new OwnershipListFilter(trackingMode: 'SERIALIZED'))->toArray();
        $this->assertSame(1, $page['total']);
        $this->assertSame([$serial['owned']->code], array_column($page['items'], 'code'));
        $this->createTenant();
        $this->expectException(TenantNotFound::class);
        $service->itemDetail($serial['owned']->code);
    }

    public function test_piece_candidates_are_paged_inside_selected_receipt(): void
    {
        // Receipt one has A/B, receipt two has C. Page two of receipt one must contain B.
        $goods = $this->fifoGoods($this->createItem('SERIALIZED'), received: false);
        $first = $this->receivePurchase($goods['purchase'], '2.000', ['PAGE-A', 'PAGE-B']);
        $this->receivePurchase($goods['purchase'], '1.000', ['PAGE-C']);
        $page = app(OwnershipService::class)->receiptLinkCandidates($goods['owned']->code, $goods['lot']->code,
            new OwnershipCandidateFilter(page: 2, perPage: 1, purchaseReceiptLineCode: $first['receiptLine']->code))->toArray();
        $this->assertSame(2, $page['total']);
        $this->assertSame([$first['inventory']['serialized_item_codes'][1]], array_column($page['items'], 'code'));
        $this->assertSame('PAGE-B', $page['items'][0]['identifier']);
    }
}
