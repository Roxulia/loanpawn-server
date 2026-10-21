<?php

namespace Tests\Feature\TenantModule;

use App\Models\InventoryModule\InventorySerializedItem;
use App\Models\OwnershipModule\OwnershipPieceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipReceiptTestCase;

class OwnershipPieceLinkTest extends OwnershipReceiptTestCase
{
    public function test_unknown_serials_become_received_and_linked_pieces(): void
    {
        // Acquisition of three SERIALIZED units creates no invented piece identity before receipt.
        // Receive actual A/B/C identifiers, then link two and one while keeping owned quantity three.
        $goods = $this->goods('SERIALIZED', '3.000', '37.02');
        $snapshot = $this->lotSnapshots();
        $this->assertSame(0, $this->countRows('inventory_serialized_items'));
        $receipt = $this->receivePurchase($goods['purchase'], '3.000', ['A', 'B', 'C']);
        $codes = $receipt['inventory']['serialized_item_codes'];
        $this->assertCount(3, $codes);
        $this->assertSame('3.000', $this->lotData($goods)['pending_link_quantity']);

        $this->linkReceipt($goods['lot'], $receipt, '2.000', array_slice($codes, 0, 2), 'pieces-ab');
        $this->assertSame('1.000', $this->lotData($goods)['pending_link_quantity']);
        $this->linkReceipt($goods['lot'], $receipt, '1.000', [$codes[2]], 'piece-c');

        $this->assertCurrentPieces($goods, $codes);
        $this->assertCount(3, $this->memberships($goods));
        $this->assertSame('0.000', $this->lotData($goods)['pending_link_quantity']);
        $this->assertOriginalAcquisition($goods, $snapshot);
    }

    #[DataProvider('badSelections')]
    public function test_duplicate_and_wrong_count_selections_do_not_change_links(string $case): void
    {
        // Use a real two-piece receipt; invalid selection must preserve its successful custody.
        // Cases distinguish duplicate codes from selecting fewer/more pieces than the exact quantity.
        $goods = $this->goods('SERIALIZED', '2.000', '24.68');
        $receipt = $this->receivePurchase($goods['purchase'], '2.000', ['A', 'B']);
        [$a, $b] = $receipt['inventory']['serialized_item_codes'];
        [$quantity, $codes] = match ($case) {
            'duplicate' => ['2.000', [$a, $a]],
            'too few' => ['2.000', [$a]],
            'too many' => ['1.000', [$a, $b]],
        };
        $this->assertLinkRejected($goods['lot'], $receipt, $quantity, $codes);
    }

    public function test_piece_must_belong_to_the_selected_receipt(): void
    {
        // Pieces from another receipt of the same purchase/item still have distinct provenance.
        // The first receipt cannot allocate a piece that arrived on the second receipt.
        $goods = $this->goods('SERIALIZED', '2.000', '24.68');
        $first = $this->receivePurchase($goods['purchase'], '1.000', ['A']);
        $second = $this->receivePurchase($goods['purchase'], '1.000', ['B']);
        $this->assertLinkRejected($goods['lot'], $first, '1.000', $second['inventory']['serialized_item_codes']);
    }

    public function test_an_identity_without_a_receipt_movement_cannot_be_linked(): void
    {
        // Deliberately persist an identity only, without custody provenance. A matching item ID
        // and plausible code are not proof that this piece was received on the selected receipt.
        $goods = $this->goods('SERIALIZED', '2.000', '24.68');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['A']);
        $piece = InventorySerializedItem::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'inventory_item_id' => $goods['item']->id, 'code' => 'UNRECEIVED-PIECE', 'identifier' => 'B']);
        $this->assertLinkRejected($goods['lot'], $receipt, '1.000', [$piece->code]);
    }

    public function test_unknown_piece_code_cannot_be_linked(): void
    {
        // A real receipt and valid capacity do not make a nonexistent selected piece valid.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['A']);
        $this->assertLinkRejected($goods['lot'], $receipt, '1.000', ['MISSING-PIECE']);
    }

    public function test_other_item_piece_cannot_be_linked(): void
    {
        // A received piece is still invalid when it belongs to another item and purchase.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['A']);
        $other = $this->goods('SERIALIZED', '1.000', '12.34');
        $otherReceipt = $this->receivePurchase($other['purchase'], '1.000', ['B']);
        $this->assertLinkRejected($goods['lot'], $receipt, '1.000', $otherReceipt['inventory']['serialized_item_codes']);
    }

    public function test_foreign_piece_cannot_be_linked_to_local_receipt(): void
    {
        // Keep the local receipt valid so the only invalid selection is the foreign tenant's piece.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['A']);
        $acting = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->goods('SERIALIZED', '1.000', '12.34');
        $foreignReceipt = $this->receivePurchase($foreign['purchase'], '1.000', ['B']);
        $this->setTenant($acting);
        $this->assertLinkRejected($goods['lot'], $receipt, '1.000', $foreignReceipt['inventory']['serialized_item_codes']);
    }

    public function test_current_piece_cannot_be_linked_to_another_lot(): void
    {
        // The second lot shares the item but has its own purchase/receipt. It cannot take a piece
        // already owned by the first lot, even when its own acquired quantity has unused capacity.
        [$first, $firstReceipt, $second, $secondReceipt] = $this->twoPieceLots();
        $this->linkReceipt($first['lot'], $firstReceipt, '1.000', $firstReceipt['inventory']['serialized_item_codes']);
        $this->assertLinkRejected($second['lot'], $secondReceipt, '1.000', $firstReceipt['inventory']['serialized_item_codes']);
    }

    public function test_database_enforces_one_current_membership_per_piece(): void
    {
        // Build two valid, independent memberships first. Then attempt a direct second-lot row for
        // the first piece so this exercises MySQL's unique constraint rather than service validation.
        [$first, $firstReceipt, $second, $secondReceipt] = $this->twoPieceLots();
        $this->linkReceipt($first['lot'], $firstReceipt, '1.000', $firstReceipt['inventory']['serialized_item_codes']);
        $this->linkReceipt($second['lot'], $secondReceipt, '1.000', $secondReceipt['inventory']['serialized_item_codes']);
        $a = OwnershipPieceMembership::query()->where('acquisition_lot_id', $first['lot']->id)->sole();
        $b = OwnershipPieceMembership::query()->where('acquisition_lot_id', $second['lot']->id)->sole();
        $before = $this->receiptState();
        $copy = $b->replicate();
        $copy->inventory_serialized_item_id = $a->inventory_serialized_item_id;
        if (array_key_exists('code', $copy->getAttributes())) {
            $copy->code = 'MC'.bin2hex(random_bytes(6));
        }
        // Generated columns must be computed by MySQL; application-maintained current keys must
        // instead identify the same first piece. Neither strategy may permit two current episodes.
        $generated = DB::select('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE ?', [$copy->getTable(), '%GENERATED%']);
        if (array_key_exists('current_piece_id', $copy->getAttributes())) {
            $copy->current_piece_id = $a->inventory_serialized_item_id;
        }
        foreach ($generated as $column) {
            $copy->offsetUnset($column->name);
        }
        // Identify the current-piece unique index by its columns, not by an invented migration name.
        // A duplicate on an unrelated source/code index must not count as proof of single membership.
        $indexes = DB::select('SELECT INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND NON_UNIQUE = 0 GROUP BY INDEX_NAME', [$copy->getTable()]);
        $currentIndex = null;
        foreach ($indexes as $index) {
            if (in_array($index->columns_list, ['current_piece_id', 'tenant_id,current_piece_id'], true)) {
                $currentIndex = $index->name;
            }
        }
        $this->assertNotNull($currentIndex, 'A current-piece unique constraint is a schema prerequisite.');
        try {
            DB::transaction(static fn () => $copy->save());
            $this->fail('MySQL must reject a second current membership for the same piece.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) ($error->errorInfo[1] ?? 0));
            $this->assertStringContainsString($currentIndex, $error->errorInfo[2] ?? '');
        }
        $this->assertSame($before, $this->receiptState());
    }

    public static function badSelections(): array
    {
        // Named independent scenarios avoid hiding different validation rules in one loop.
        return ['duplicate' => ['duplicate'], 'too few' => ['too few'], 'too many' => ['too many']];
    }

    private function twoPieceLots(): array
    {
        // Reuse one explicit item identity but separate paid sources and real received pieces.
        $first = $this->goods('SERIALIZED', '1.000', '12.34');
        $purchase = $this->paidPurchase($first['item'], '1.000', '12.34');
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $second = ['purchase' => $purchase, 'item' => $first['item'], 'lot' => $lot, 'owned' => $first['owned']];

        return [$first, $this->receivePurchase($first['purchase'], '1.000', ['A']),
            $second, $this->receivePurchase($purchase, '1.000', ['B'])];
    }
}
