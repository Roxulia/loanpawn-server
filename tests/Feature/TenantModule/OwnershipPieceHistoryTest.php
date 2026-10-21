<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\{ApiException, InvalidTenantRequest};
use App\Models\OwnershipModule\{OwnedItem, OwnershipPieceMembership, OwnershipReceiptLink};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Tests\Support\OwnershipPieceTestCase;

class OwnershipPieceHistoryTest extends OwnershipPieceTestCase
{
    public function test_sale_closes_membership_but_keeps_purchase_history(): void
    {
        // A linked piece has one current episode. Sale must end it and consume its received allocation,
        // while preserving the immutable acquisition lot and original historical receipt-link quantity.
        $goods = $this->linkedPiece();
        $lots = $this->lotSnapshots();
        $original = $this->memberships($goods);
        $this->sellPiece($goods);

        $episodes = $this->memberships($goods);
        $this->assertCount(1, $episodes);
        $this->assertSame($original[0]['id'], $episodes[0]['id']);
        $this->assertNotNull($episodes[0]['ended_at']);
        $this->assertCurrentPieces($goods, []);
        $this->assertSame($lots, $this->lotSnapshots());
        $this->assertBalances((int) $goods['lot']->owned_item_id, '0.000');
        $link = OwnershipReceiptLink::query()->where('acquisition_lot_id', $goods['lot']->id)->sole();
        $this->assertSame('1.000', $link->linked_quantity);
        $this->assertSame('0.000', $link->remaining_owned_quantity);
        $this->assertSame('0.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
    }

    public function test_original_sale_return_adds_an_episode_in_the_original_lot(): void
    {
        // Use the real original allocation as return evidence, not an arbitrary positive quantity.
        // Inactive historical currency must not prevent restoring the original saved cost/lot.
        $goods = $this->linkedPiece();
        $lots = $this->lotSnapshots();
        $sale = $this->sellPiece($goods);
        $closed = $this->memberships($goods);
        $line = $this->returnLine($goods, $sale);
        $this->ownershipFixture['currency']->update(['is_active' => false]);
        $this->restorePiece($goods, $line);

        $episodes = $this->memberships($goods);
        $this->assertCount(2, $episodes);
        $this->assertSame($closed[0], $episodes[0]);
        $this->assertNotSame($episodes[0]['id'], $episodes[1]['id']);
        $this->assertNull($episodes[1]['ended_at']);
        $this->assertCurrentPieces($goods, [$goods['piece']]);
        $this->assertSame($lots, $this->lotSnapshots());
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('purchase_receipt_lines'));
        $this->assertBalances((int) $goods['lot']->owned_item_id, '1.000');
        $this->assertSame('1.000', OwnershipReceiptLink::query()->where('acquisition_lot_id', $goods['lot']->id)
            ->sole()->remaining_owned_quantity);
    }

    public function test_later_paid_repurchase_opens_membership_in_a_new_lot(): void
    {
        // A different paid source can reacquire a sold physical piece. Its old ended episode and lot
        // remain historical; the new receipt must reuse the exact Inventory piece identity.
        $goods = $this->linkedPiece();
        $this->sellPiece($goods);
        $oldEpisodes = $this->memberships($goods);
        $oldLot = $this->lotSnapshots()[$goods['lot']->code];
        $purchase = $this->paidPurchase($goods['item'], '1.000', '12.34');
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $receipt = $this->receivePurchase($purchase, '1.000', ['HISTORY-A']);
        $this->assertSame([$goods['piece']], $receipt['inventory']['serialized_item_codes']);
        $this->linkReceipt($lot, $receipt, '1.000', [$goods['piece']]);
        $new = ['lot' => $lot, 'owned' => OwnedItem::query()->findOrFail($lot->owned_item_id)];

        $this->assertNotSame($goods['lot']->code, $lot->code);
        $this->assertSame($oldLot, $this->lotSnapshots()[$goods['lot']->code]);
        $this->assertSame($oldEpisodes, $this->memberships($goods));
        $this->assertCurrentPieces($goods, []);
        $this->assertCurrentPieces($new, [$goods['piece']]);
        $this->assertSame(1, OwnershipPieceMembership::query()->whereNull('ended_at')->count());
        $this->assertSame(1, $this->countRows('inventory_serialized_items'));
    }

    public function test_repeated_return_cannot_restore_the_same_piece_twice(): void
    {
        // The second request has a new real return-line code, but references the already returned
        // original issued allocation. A new key/source cannot exceed its remaining return allowance.
        $goods = $this->linkedPiece();
        $sale = $this->sellPiece($goods);
        $first = $this->returnLine($goods, $sale);
        $this->restorePiece($goods, $first);
        $second = $this->returnLine($goods, $sale);
        $before = $this->receiptState();
        try {
            app(OwnershipService::class)->restoreForSaleReturn($goods['owned']->code, $goods['lot']->code,
                '1.000', $second->code, serializedItemCodes: [$goods['piece']]);
            $this->fail('A new return source cannot restore an already returned piece again.');
        } catch (ApiException $error) {
            $this->assertInstanceOf(InvalidTenantRequest::class, $error);
        }
        $this->assertSame($before, $this->receiptState());
        $this->assertCurrentPieces($goods, [$goods['piece']]);
    }

    public function test_return_source_cannot_restore_another_items_piece(): void
    {
        // Both pieces were genuinely sold, but this return line authorizes only the second sale.
        // Passing the first lot and its issued piece must not borrow that unrelated return allowance.
        $first = $this->linkedPiece();
        $this->sellPiece($first);
        $second = $this->linkedPiece('HISTORY-B');
        $sale = $this->sellPiece($second);
        $return = $this->returnLine($second, $sale);
        $before = $this->receiptState();
        try {
            app(OwnershipService::class)->restoreForSaleReturn($first['owned']->code, $first['lot']->code,
                '1.000', $return->code, serializedItemCodes: [$first['piece']]);
            $this->fail('Return evidence must match the original lot and issued piece.');
        } catch (ApiException $error) {
            $this->assertInstanceOf(InvalidTenantRequest::class, $error);
        }
        $this->assertSame($before, $this->receiptState());
    }

    public function test_return_cannot_restore_more_than_the_issued_quantity(): void
    {
        // Only one unit was issued and the return source authorizes that unit. Asking to restore
        // two is invalid for both source capacity and exact piece-count matching; neither may be ignored.
        $goods = $this->linkedPiece();
        $sale = $this->sellPiece($goods);
        $return = $this->returnLine($goods, $sale);
        $before = $this->receiptState();
        try {
            app(OwnershipService::class)->restoreForSaleReturn($goods['owned']->code, $goods['lot']->code,
                '2.000', $return->code, serializedItemCodes: [$goods['piece']]);
            $this->fail('Return cannot exceed its original issued quantity.');
        } catch (ApiException $error) {
            $this->assertInstanceOf(InvalidTenantRequest::class, $error);
        }
        $this->assertSame($before, $this->receiptState());
    }
}
