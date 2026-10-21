<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\{InventoryIssueRequest, InventoryReservationRequest};
use App\Enums\InventoryIssueContext;
use App\Exceptions\InvalidTenantRequest;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Models\SalesModule\SaleOrderReservation;
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipPurchaseTestCase;

/** OWN-06e: UNIQUE ownership cannot overlap, but a disposed identity may be acquired again. */
class OwnershipUniqueTest extends OwnershipPurchaseTestCase
{
    public function test_unique_item_cannot_be_owned_twice_before_receipt(): void
    {
        // Both purchases are paid and explicitly select the same UNIQUE identity. The first already
        // owns the item even though physical stock is still zero; that must block the second source.
        $item = $this->createItem('UNIQUE');
        $first = $this->paidPurchase($item, '1.000', '12.34');
        $second = $this->paidPurchase($item, '1.000', '12.34');
        $result = $this->acquire($first);
        $lot = $this->assertAcquisition($first, $result);

        $this->assertRejected($second['line']->code, InvalidTenantRequest::class);

        // Rejection must preserve the first source and its single unit rather than splitting it
        // between two lots or treating absence of physical stock as absence of Ownership.
        $this->assertBalances((int) $lot->owned_item_id, '1.000');
        $this->assertSame(1, $this->countRows('owned_items'));
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('ownership_movements'));
        $this->assertNoStock($item);
    }

    public function test_fully_reserved_unique_item_still_blocks_new_acquisition(): void
    {
        // Reserve the real received/linked item for sale without consuming Ownership. Available
        // quantity is zero, but owned quantity is still one, so reacquisition is not yet permitted.
        $item = $this->createItem('UNIQUE');
        $first = $this->paidPurchase($item, '1.000', '12.34');
        $lot = $this->assertAcquisition($first, $this->acquire($first));
        $this->reserveUnique($first, $lot);
        $second = $this->paidPurchase($item, '1.000', '12.34');
        $this->assertRejected($second['line']->code, InvalidTenantRequest::class);

        $rows = $this->lotMovements($lot);
        $this->assertSame('1.000', (string) (clone $rows)->sum('owned_delta'));
        $this->assertSame('1.000', (string) (clone $rows)->sum('reserved_delta'));
        $this->assertSame('0.000', (string) (clone $rows)->sum('pledged_delta'));
        $this->assertSame(1, $this->countRows('acquisition_lots'));
    }

    public function test_disposed_unique_identity_can_have_a_new_purchase_lot(): void
    {
        // Acquire, receive and link the first paid unit, then fully consume its Ownership and issue
        // its physical stock through real primitives. No balances or historical rows are manually reset.
        $item = $this->createItem('UNIQUE');
        $first = $this->paidPurchase($item, '1.000', '12.34');
        $firstResult = $this->acquire($first);
        $oldLot = $this->assertAcquisition($first, $firstResult);
        $this->disposeUnique($first, $oldLot);
        $this->assertBalances((int) $oldLot->owned_item_id, '0.000');
        $this->assertSame('0.000', app(InventoryService::class)->itemDetail($item->code)->totalQuantity);
        $oldSnapshot = $this->lotSnapshots()[$oldLot->code];
        $oldMovements = $this->lotMovements($oldLot)->orderBy('id')->get()->toArray();

        // A distinct paid purchase is allowed to reuse the now-disposed identity. Its later settlement
        // date belongs to a new lot; it must never reopen or rewrite the original purchase lot.
        $second = $this->paidPurchase($item, '1.000', '12.34');
        $second['payment']->update(['paid_at' => '2026-10-15']);
        $before = $this->otherState();
        $result = $this->acquire($second);
        $newLot = $this->assertAcquisition($second, $result, expectedDate: '2026-10-15');

        $this->assertSame($firstResult->ownedItemCode, $result->ownedItemCode);
        $this->assertSame((int) $oldLot->owned_item_id, (int) $newLot->owned_item_id);
        $this->assertNotSame($oldLot->code, $newLot->code);
        $this->assertSame($oldSnapshot, $this->lotSnapshots()[$oldLot->code]);
        $this->assertEquals($oldMovements, $this->lotMovements($oldLot)->orderBy('id')->get()->toArray());
        $this->assertBalances((int) $newLot->owned_item_id, '1.000');
        $this->assertSame('0.000', (string) $this->lotMovements($oldLot)->sum('owned_delta'));
        $this->assertSame('1.000', (string) $this->lotMovements($newLot)->sum('owned_delta'));

        // Only the new lot may have positive Ownership. Reacquisition itself receives no stock,
        // preserves the old Inventory issue/history, and does not create a second item or aggregate.
        $currentLots = DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('owned_item_id', $newLot->owned_item_id)->groupBy('acquisition_lot_id')
            ->havingRaw('SUM(owned_delta) > 0')->pluck('acquisition_lot_id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $newLot->id], $currentLots);
        $this->assertSame(1, $this->countRows('inventory_items'));
        $this->assertSame(1, $this->countRows('owned_items'));
        $this->assertSame(2, $this->countRows('acquisition_lots'));
        $this->assertSame(0, $this->countRows('inventory_serialized_items'));
        $this->assertSame($before, $this->otherState());
    }

    private function reserveUnique(array $purchase, AcquisitionLot $lot): array
    {
        // Create receipt provenance and link the actual unit before attempting any sale reservation.
        // Both Inventory custody and Ownership availability must refer to this same item and source.
        $item = $purchase['item'];
        $this->linkReceipt($lot, $this->receivePurchase($purchase, '1.000'), '1.000');
        $line = $this->saleLine($lot, $item, '1.000');
        $owned = OwnedItem::query()->findOrFail($lot->owned_item_id);
        $location = $this->ownershipFixture['location'];
        $reservation = app(InventoryService::class)->reserveForSale(new InventoryReservationRequest(
            inventoryItemCode: $item->code, locationCode: $location->code, quantity: '1.000',
            sourceType: 'SaleOrderLine', sourceCode: $line->code,
        ));
        app(OwnershipService::class)->reserveSale($owned->code, $lot->code, '1.000', $line->code);

        // Persist the real caller's reservation reference without manufacturing an Ownership balance.
        // This minimal source fixture supports quantity operations, not complete sale/payment acceptance.
        $saleReservation = SaleOrderReservation::query()->create([
            'tenant_id' => $this->ownershipFixture['tenant']->id, 'code' => 'UR'.bin2hex(random_bytes(6)),
            'sales_order_line_id' => $line->id, 'acquisition_lot_id' => $lot->id,
            'inventory_location_id' => $location->id, 'inventory_location_code' => $location->code,
            'inventory_reservation_code' => $reservation['reservation_code'], 'quantity' => '1.000',
            'remaining_quantity' => '1.000', 'serialized_item_codes' => [], 'status' => 'ACTIVE',
        ]);

        return [$line, $saleReservation];
    }

    private function disposeUnique(array $purchase, AcquisitionLot $lot): void
    {
        // Keep the controlled quantity disposal in one transaction: a failed issue or consumption
        // must not prepare a half-disposed item that would make the reacquisition test misleading.
        DB::transaction(function () use ($purchase, $lot): void {
            [$line, $reservation] = $this->reserveUnique($purchase, $lot);
            $allocation = $this->allocation($lot, $line);
            $owned = OwnedItem::query()->findOrFail($lot->owned_item_id);
            app(OwnershipService::class)->consumeSaleReservation($owned->code, $lot->code, '1.000', $line->code);
            app(InventoryService::class)->issueStock(new InventoryIssueRequest(
                inventoryItemCode: $purchase['item']->code, locationCode: $this->ownershipFixture['location']->code,
                quantity: '1.000', operationContext: InventoryIssueContext::Sale,
                sourceType: 'SaleDeliveryAllocation', sourceCode: $allocation->code,
                reservationCode: $reservation->inventory_reservation_code,
                expectedReservationSourceType: 'SaleOrderLine', expectedReservationSourceCode: $line->code,
            ));
            $reservation->update(['remaining_quantity' => '0.000', 'status' => 'CONSUMED']);
        });
    }

    private function lotMovements(AcquisitionLot $lot): \Illuminate\Database\Query\Builder
    {
        // Read actual tenant/lot ledger rows for both history preservation and exact balance checks.
        // Nothing here edits, synthesizes or recomputes the movement records under test.
        return DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $lot->id);
    }
}
