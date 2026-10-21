<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\{InventoryIssueRequest, InventoryReservationRequest, InventorySaleReturnRequest};
use App\Enums\InventoryIssueContext;
use App\Models\SalesModule\{SaleReturn, SaleReturnLine};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;

/** Real source/custody helpers for serialized history; no payment, refund or business authorization mocks. */
abstract class OwnershipPieceTestCase extends OwnershipReceiptTestCase
{
    protected function linkedPiece(string $identifier = 'HISTORY-A'): array
    {
        // Establish actual paid ownership, physical receipt and one current membership through services.
        // Later tests may close/restore that episode but must never replace it with a seeded balance.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', [$identifier]);
        $codes = $receipt['inventory']['serialized_item_codes'];
        $this->linkReceipt($goods['lot'], $receipt, '1.000', $codes);

        return $goods + ['receipt' => $receipt, 'piece' => $codes[0]];
    }

    protected function sellPiece(array $goods): array
    {
        // Coordinate real source reservation, Ownership consumption and physical issue in one controlled
        // transaction. This exercises quantity primitives, not a complete Quick Sell/payment workflow.
        return DB::transaction(function () use ($goods): array {
            $line = $this->saleLine($goods['lot'], $goods['item'], '1.000');
            $codes = [$goods['piece']];
            $ownership = app(OwnershipService::class);
            $inventory = app(InventoryService::class);
            $ownership->reserveSale($goods['owned']->code, $goods['lot']->code, '1.000', $line->code,
                serializedItemCodes: $codes);
            $reservation = $inventory->reserveForSale(new InventoryReservationRequest(
                inventoryItemCode: $goods['item']->code, locationCode: $this->ownershipFixture['location']->code,
                quantity: '1.000', sourceType: 'SaleOrderLine', sourceCode: $line->code, serializedItemCodes: $codes,
            ));
            $allocation = $this->allocation($goods['lot'], $line, $codes);
            $ownership->consumeSaleReservation($goods['owned']->code, $goods['lot']->code, '1.000', $line->code,
                serializedItemCodes: $codes);
            $inventory->issueStock(new InventoryIssueRequest(
                inventoryItemCode: $goods['item']->code, locationCode: $this->ownershipFixture['location']->code,
                quantity: '1.000', operationContext: InventoryIssueContext::Sale,
                sourceType: 'SaleDeliveryAllocation', sourceCode: $allocation->code,
                reservationCode: $reservation['reservation_code'], serializedItemCodes: $codes,
                expectedReservationSourceType: 'SaleOrderLine', expectedReservationSourceCode: $line->code,
            ));

            return ['line' => $line, 'allocation' => $allocation];
        });
    }

    protected function returnLine(array $goods, array $sale): SaleReturnLine
    {
        // Record a real return source tied to the original delivered allocation. This does not perform
        // refunds or receive stock; the caller separately invokes Inventory and Ownership primitives.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        $return = SaleReturn::query()->create(['tenant_id' => $tenantId, 'code' => 'HR'.bin2hex(random_bytes(6)),
            'sales_order_id' => $sale['line']->sales_order_id, 'returned_at' => '2026-10-15',
            'created_by' => $this->ownershipFixture['actor']->id]);

        return SaleReturnLine::query()->create(['tenant_id' => $tenantId, 'code' => 'HL'.bin2hex(random_bytes(6)),
            'sales_return_id' => $return->id, 'sales_delivery_allocation_id' => $sale['allocation']->id,
            'inventory_location_id' => $this->ownershipFixture['location']->id, 'quantity' => '1.000',
            'serialized_item_codes' => json_encode([$goods['piece']], JSON_THROW_ON_ERROR), 'disposition' => 'RESTOCK',
            'cash_refund_amount' => '0.00', 'receivable_credit_amount' => '0.00']);
    }

    protected function restorePiece(array $goods, SaleReturnLine $line): void
    {
        // Restore the original physical identity and its original lot together. The old purchase receipt
        // must not be inserted again; a new membership episode must reference the authorized return.
        DB::transaction(function () use ($goods, $line): void {
            app(InventoryService::class)->receiveSaleReturn(new InventorySaleReturnRequest(
                inventoryItemCode: $goods['item']->code, locationCode: $this->ownershipFixture['location']->code,
                quantity: '1.000', sourceCode: $line->code, serializedItemCodes: [$goods['piece']],
            ));
            app(OwnershipService::class)->restoreForSaleReturn($goods['owned']->code, $goods['lot']->code,
                '1.000', $line->code, serializedItemCodes: [$goods['piece']]);
        });
    }
}
