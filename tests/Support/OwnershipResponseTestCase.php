<?php

namespace Tests\Support;

use App\DataObjects\BaseDataObject;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipItemSummary;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipLot;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipMovementRow;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipReceiptCandidate;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipPiece;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Plain response fixtures shared by OWN-05d serialization and pagination tests. */
abstract class OwnershipResponseTestCase extends TestCase
{
    protected function setUp(): void
    {
        // Response construction and serialization must not request a database connection.
        parent::setUp();
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        DB::shouldReceive('select')->never();
        DB::shouldReceive('selectOne')->never();
    }

    protected function row(string $kind): BaseDataObject
    {
        // Build actual typed contracts from scalar facts, never Eloquent models or lazy relations.
        return match ($kind) {
            'item' => $this->summary(),
            'lot' => $this->lot(),
            'movement' => new OwnershipMovementRow(
                code: 'MOVE-A', lotCode: 'LOT-A', operation: 'SALE_RESERVE', quantity: '1.000',
                ownedDelta: '0.000', reservedDelta: '1.000', pledgedDelta: '0.000',
                sourceModule: 'SALES', sourceType: 'SaleLine', sourceCode: 'SALE-LINE-A',
                occurredAt: '2026-10-21T12:00:00Z', recordedAt: '2026-10-21T12:00:01Z',
            ),
            'receipt' => new OwnershipReceiptCandidate(
                purchaseReceiptLineCode: 'RECEIPT-LINE-A', purchaseOrderLineCode: 'PURCHASE-LINE-A',
                receivedAt: '2026-10-21', inventoryItemCode: 'ITEM-A',
                receivedQuantity: '3.000', linkedQuantity: '1.000', remainingToLinkQuantity: '2.000',
            ),
            'piece' => new OwnershipPiece(
                code: 'PIECE-A', identifier: '0', purchaseReceiptLineCode: 'RECEIPT-LINE-A',
                locationCode: 'SHOP-A', locationName: 'Main shop', linkState: 'PENDING', isEligible: false,
            ),
        };
    }

    protected function summary(): OwnershipItemSummary
    {
        // Keep Ownership availability, physical custody and receipt-backed quantities distinct.
        return new OwnershipItemSummary(
            code: 'OWN-A', inventoryItemCode: 'ITEM-A', catalogItemCode: null,
            name: 'Test goods', description: null, trackingMode: 'QUANTITY',
            unitCode: 'unit', unitName: 'Piece', quantityScale: '1.000',
            ownedQuantity: '10.000', reservedQuantity: '2.000', pledgedQuantity: '1.000', availableQuantity: '7.000',
            physicalQuantity: '6.000', receivedBackedQuantity: '4.000', awaitingReceiptQuantity: '4.000',
            pendingLinkQuantity: '2.000', lotCount: 2, lifecycleStatus: 'ACTIVE', updateKey: 3,
            imageUrl: null, imageUrlExpiresAt: null,
        );
    }

    protected function expectedRow(string $kind): array
    {
        // Literal wire expectations detect missing fields, leaked IDs and decimal type changes.
        return match ($kind) {
            'item' => $this->expectedSummary(),
            'lot' => [
                'code' => 'LOT-A', 'source_module' => 'PURCHASING', 'source_type' => 'PurchaseOrderLine',
                'source_code' => 'PURCHASE-LINE-A', 'acquired_at' => '2026-10-20', 'acquired_quantity' => '10.000',
                'owned_quantity' => '10.000', 'reserved_quantity' => '2.000', 'pledged_quantity' => '1.000',
                'available_quantity' => '7.000', 'received_backed_quantity' => '4.000',
                'awaiting_receipt_quantity' => '4.000', 'pending_link_quantity' => '2.000',
                'unit_cost_basis' => '99999999999999.9999', 'estimated_unit_value' => null,
                'currency_code' => 'MMK', 'description' => null,
            ],
            'movement' => [
                'code' => 'MOVE-A', 'lot_code' => 'LOT-A', 'operation' => 'SALE_RESERVE', 'quantity' => '1.000',
                'owned_delta' => '0.000', 'reserved_delta' => '1.000', 'pledged_delta' => '0.000',
                'source_module' => 'SALES', 'source_type' => 'SaleLine', 'source_code' => 'SALE-LINE-A',
                'occurred_at' => '2026-10-21T12:00:00Z', 'recorded_at' => '2026-10-21T12:00:01Z',
            ],
            'receipt' => [
                'purchase_receipt_line_code' => 'RECEIPT-LINE-A', 'purchase_order_line_code' => 'PURCHASE-LINE-A',
                'received_at' => '2026-10-21', 'inventory_item_code' => 'ITEM-A',
                'received_quantity' => '3.000', 'linked_quantity' => '1.000', 'remaining_to_link_quantity' => '2.000',
            ],
            'piece' => [
                'code' => 'PIECE-A', 'identifier' => '0', 'purchase_receipt_line_code' => 'RECEIPT-LINE-A',
                'location_code' => 'SHOP-A', 'location_name' => 'Main shop', 'link_state' => 'PENDING', 'is_eligible' => false,
            ],
        };
    }

    protected function expectedSummary(): array
    {
        // Fix the bounded item summary contract without embedded lots, history, pieces or raw paths.
        return [
            'code' => 'OWN-A', 'inventory_item_code' => 'ITEM-A', 'catalog_item_code' => null,
            'name' => 'Test goods', 'description' => null, 'tracking_mode' => 'QUANTITY',
            'unit_code' => 'unit', 'unit_name' => 'Piece', 'quantity_scale' => '1.000',
            'owned_quantity' => '10.000', 'reserved_quantity' => '2.000', 'pledged_quantity' => '1.000',
            'available_quantity' => '7.000', 'physical_quantity' => '6.000', 'received_backed_quantity' => '4.000',
            'awaiting_receipt_quantity' => '4.000', 'pending_link_quantity' => '2.000',
            'lot_count' => 2, 'lifecycle_status' => 'ACTIVE', 'update_key' => 3,
            'image_url' => null, 'image_url_expires_at' => null,
        ];
    }

    private function lot(): OwnershipLot
    {
        // Include a maximum-precision historical cost so serialization cannot hide float conversion.
        return new OwnershipLot(
            code: 'LOT-A', sourceModule: 'PURCHASING', sourceType: 'PurchaseOrderLine', sourceCode: 'PURCHASE-LINE-A',
            acquiredAt: '2026-10-20', acquiredQuantity: '10.000', ownedQuantity: '10.000', reservedQuantity: '2.000',
            pledgedQuantity: '1.000', availableQuantity: '7.000', receivedBackedQuantity: '4.000',
            awaitingReceiptQuantity: '4.000', pendingLinkQuantity: '2.000', unitCostBasis: '99999999999999.9999',
            estimatedUnitValue: null, currencyCode: 'MMK', description: null,
        );
    }
}
