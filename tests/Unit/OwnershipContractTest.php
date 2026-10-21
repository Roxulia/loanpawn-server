<?php

namespace Tests\Unit;

use App\DataObjects\ResponseObjects\{InventoryLocationBalance, InventoryLocationDetail, OwnershipItemDetail, OwnershipPiece, OwnershipResult};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipResponseTestCase;

class OwnershipContractTest extends OwnershipResponseTestCase
{
    #[DataProvider('rowKinds')]
    public function test_row_serializes_public_fields(string $kind): void
    {
        // Exact arrays prohibit extra internal IDs, model relations or raw storage paths.
        $row = $this->row($kind);
        $this->assertSame($this->expectedRow($kind), $row->toArray());
    }

    #[DataProvider('rowKinds')]
    public function test_json_keeps_exact_strings(string $kind): void
    {
        // JSON encoding must preserve decimal strings, nullable values and string zero identifiers.
        $data = json_decode(json_encode($this->row($kind)->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->expectedRow($kind), $data);
    }

    public function test_detail_reuses_inventory_location_projection(): void
    {
        // Ownership detail adds custody projections without embedding unbounded lots or pieces.
        $location = new InventoryLocationDetail('SHOP-A', 'Main shop', 'SHOP', true, true, true, 0);
        $balance = new InventoryLocationBalance($location, '6.000', '1.000', '5.000');
        $detail = new OwnershipItemDetail($this->summary(), locations: [$balance], receiptLinkState: 'PENDING');
        $this->assertSame(array_merge($this->expectedSummary(), [
            'locations' => [['location' => ['code' => 'SHOP-A', 'name' => 'Main shop', 'type' => 'SHOP',
                'is_default' => true, 'is_active' => true, 'is_sellable' => true, 'update_key' => 0],
                'quantity' => '6.000', 'reserved_quantity' => '1.000', 'available_quantity' => '5.000']],
            'receipt_link_state' => 'PENDING',
        ]), $detail->toArray());
    }

    public function test_piece_identifier_can_be_unknown(): void
    {
        // Unknown identifiers remain null rather than being replaced with invented serial numbers.
        $piece = new OwnershipPiece(code: 'PIECE-A', identifier: null, purchaseReceiptLineCode: 'RECEIPT-LINE-A',
            locationCode: 'SHOP-A', locationName: 'Main shop', linkState: 'PENDING', isEligible: false);
        $this->assertSame(array_replace($this->expectedRow('piece'), ['identifier' => null]), $piece->toArray());
    }

    public function test_movement_keeps_signed_decimal_deltas(): void
    {
        // Consumption must expose negative owned and reserved changes as exact strings.
        $row = new \App\DataObjects\ResponseObjects\OwnershipMovementRow(
            code: 'MOVE-A', lotCode: 'LOT-A', operation: 'SALE_CONSUME', quantity: '1.000',
            ownedDelta: '-1.000', reservedDelta: '-1.000', pledgedDelta: '0.000',
            sourceModule: 'SALES', sourceType: 'SaleLine', sourceCode: 'SALE-LINE-A',
            occurredAt: '2026-10-21T12:00:00Z', recordedAt: '2026-10-21T12:00:01Z',
        );
        $this->assertSame(array_replace($this->expectedRow('movement'), [
            'operation' => 'SALE_CONSUME', 'owned_delta' => '-1.000', 'reserved_delta' => '-1.000',
        ]), $row->toArray());
    }

    public function test_result_contains_only_affected_references(): void
    {
        // Supply this operation's references; service-level affected-row selection is tested later.
        $result = new OwnershipResult(ownedItemCode: 'OWN-A', updateKey: 4, lotCodes: ['LOT-NEW'],
            movementCodes: ['MOVE-NEW'], receiptLinkCodes: ['LINK-NEW'], serializedItemCodes: ['PIECE-NEW']);
        $this->assertSame([
            'owned_item_code' => 'OWN-A', 'update_key' => 4, 'lot_codes' => ['LOT-NEW'],
            'movement_codes' => ['MOVE-NEW'], 'receipt_link_codes' => ['LINK-NEW'], 'serialized_item_codes' => ['PIECE-NEW'],
        ], $result->toArray());
    }

    public function test_result_allows_no_affected_pieces(): void
    {
        // Quantity-only operations keep absent reference groups as empty lists.
        $result = new OwnershipResult(ownedItemCode: 'OWN-A', updateKey: 4, lotCodes: ['LOT-A'], movementCodes: ['MOVE-A']);
        $this->assertSame([
            'owned_item_code' => 'OWN-A', 'update_key' => 4, 'lot_codes' => ['LOT-A'],
            'movement_codes' => ['MOVE-A'], 'receipt_link_codes' => [], 'serialized_item_codes' => [],
        ], $result->toArray());
    }

    public static function rowKinds(): array
    {
        // Share the same public-wire checks across all separately paginated row types.
        return ['item' => ['item'], 'lot' => ['lot'], 'movement' => ['movement'], 'receipt' => ['receipt'], 'piece' => ['piece']];
    }
}
