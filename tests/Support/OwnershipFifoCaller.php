<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\{InventoryIssueRequest, InventoryReservationRequest};
use App\Enums\InventoryIssueContext;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\OwnershipModule\AcquisitionLot;
use App\Models\SalesModule\{SaleDelivery, SaleDeliveryAllocation, SaleOrderLine};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use App\Support\TenantContext;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Assert;
use RuntimeException;

/** Minimal real-sale caller for FIFO atomicity; no payment, profit or complete checkout claims. */
final class OwnershipFifoCaller
{
    public static function lockSources(array $sources): void
    {
        // Source locks precede allocator item anchors. Never prelock stock here: FIFO must do that itself.
        $lines = SaleOrderLine::query()->whereIn('code', array_values($sources))->orderBy('id')->lockForUpdate()->get();
        if ($lines->count() !== count($sources)) {
            throw new LogicException('FIFO caller requires real tenant-bound sale lines.');
        }
    }

    public static function apply(array $result, array $sources, ?int $failAfter = null): void
    {
        // Apply the allocator's exact rows through real module services in the caller transaction.
        $ownership = app(OwnershipService::class);
        $inventory = app(InventoryService::class);
        $applied = [];
        foreach ($result['allocations'] as $index => $row) {
            $line = SaleOrderLine::query()->where('code', $sources[$row['lot_code']])->firstOrFail();
            $codes = $row['serialized_item_codes'];
            $ownership->reserveSale($result['owned_item_code'], $row['lot_code'], $row['quantity'], $line->code,
                serializedItemCodes: $codes);
            $reservation = $inventory->reserveForSale(new InventoryReservationRequest(
                inventoryItemCode: $result['inventory_item_code'], locationCode: $row['location_code'],
                quantity: $row['quantity'], sourceType: 'SaleOrderLine', sourceCode: $line->code,
                serializedItemCodes: $codes,
            ));
            $allocation = self::deliveryAllocation($line, $row);
            $ownership->consumeSaleReservation($result['owned_item_code'], $row['lot_code'], $row['quantity'], $line->code,
                serializedItemCodes: $codes);
            $inventory->issueStock(new InventoryIssueRequest(
                inventoryItemCode: $result['inventory_item_code'], locationCode: $row['location_code'],
                quantity: $row['quantity'], operationContext: InventoryIssueContext::Sale,
                sourceType: 'SaleDeliveryAllocation', sourceCode: $allocation->code,
                reservationCode: $reservation['reservation_code'], serializedItemCodes: $codes,
                expectedReservationSourceType: 'SaleOrderLine', expectedReservationSourceCode: $line->code,
            ));
            // A source can span locations, so compare its cumulative reduction and this issue separately.
            $applied[$line->code] = ($applied[$line->code] ?? 0) + InventoryQuantity::parse($row['quantity']);
            self::assertApplied($line, $allocation, InventoryQuantity::format($applied[$line->code]), $row['quantity']);

            // Inject failure only after actual Ownership consumption and Inventory issue have happened.
            if ($failAfter === $index + 1) {
                throw new RuntimeException('FIFO caller failed after applying allocation.');
            }
        }
    }

    private static function deliveryAllocation(SaleOrderLine $line, array $row): SaleDeliveryAllocation
    {
        // Persist required business provenance, preserving the selected location, lot, pieces and quantity.
        $tenantId = app(TenantContext::class)->id();
        $lot = AcquisitionLot::query()->where('code', $row['lot_code'])->firstOrFail();
        $location = InventoryLocation::query()->where('code', $row['location_code'])->firstOrFail();
        $delivery = SaleDelivery::query()->create(['tenant_id' => $tenantId, 'code' => 'FD'.bin2hex(random_bytes(6)),
            'sales_order_id' => $line->sales_order_id, 'delivered_at' => CarbonImmutable::now('UTC')->toDateString(),
            'created_by' => $line->order->created_by]);

        return SaleDeliveryAllocation::query()->create(['tenant_id' => $tenantId, 'code' => 'FA'.bin2hex(random_bytes(6)),
            'sales_delivery_id' => $delivery->id, 'sales_order_line_id' => $line->id,
            'acquisition_lot_id' => $lot->id, 'inventory_location_id' => $location->id,
            'quantity' => $row['quantity'], 'unit_cost' => $row['unit_cost_basis'],
            'unit_price' => $line->unit_price, 'serialized_item_codes' => $row['serialized_item_codes']]);
    }

    private static function assertApplied(SaleOrderLine $line, SaleDeliveryAllocation $allocation,
        string $sourceQuantity, string $issueQuantity): void
    {
        // Observe persisted effects before injected failure/rollback; no-op mutations cannot satisfy the harness.
        $tenantId = app(TenantContext::class)->id();
        $owned = DB::table('ownership_movements')->where('tenant_id', $tenantId)
            ->where('source_type', 'SaleOrderLine')->where('source_code', $line->code)->sum('owned_delta');
        $issued = DB::table('inventory_movements')->where('tenant_id', $tenantId)
            ->where('source_type', 'SaleDeliveryAllocation')->where('source_code', $allocation->code)
            ->whereNotNull('from_location_id')->sum('quantity');
        Assert::assertSame('-'.$sourceQuantity, (string) $owned);
        Assert::assertSame($issueQuantity, (string) $issued);
    }
}
