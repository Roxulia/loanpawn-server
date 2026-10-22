<?php

namespace Tests\Support;

use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest;
use App\Exceptions\{ApiException, TenantNotFound};
use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipMovement};
use App\Models\SalesModule\{SaleOrder, SaleOrderLine, SaleDelivery, SaleDeliveryAllocation};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;

/** Shared real-source assertions for Ownership purchase acquisition tests. */
abstract class OwnershipPurchaseTestCase extends OwnershipMySqlTestCase
{
    protected function paidPurchase(InventoryItem $item, string $quantity = '10.000', string $paid = '123.40'): array
    {
        // Persist a confirmed purchase and its payment without creating Ownership or receiving stock.
        // Different order, settlement and frozen recording dates expose use of the wrong acquisition date.
        $purchase = $this->createPurchase($item, quantity: $quantity, paid: $paid);
        $purchase['order']->update(['ordered_at' => '2026-10-10']);
        $purchase['payment']->update(['paid_at' => '2026-10-12']);

        return $purchase;
    }

    protected function otherState(bool $includeVersions = true): array
    {
        // Capture this tenant's custody and financial state after its source payment fixture exists.
        // Acquisition may add Ownership records, but must not receive goods or post the payment again.
        $state = [];
        foreach (['purchase_payments', 'purchase_receipts', 'purchase_receipt_lines',
            'inventory_movements', 'inventory_serialized_items', 'inventory_reservations',
            'financial_account_transactions', 'tenant_accounting_transactions'] as $table) {
            $state[$table] = $this->countRows($table);
        }
        $state['cash_balance'] = (string) $this->ownershipFixture['account']->fresh()->balance;
        if ($includeVersions) {
            // Existing item versions must stay unchanged. Identity-creation cases check their new
            // item's initial version separately because creating that identity changes this list.
            $state['item_versions'] = InventoryItem::query()->orderBy('id')->pluck('update_key', 'code')->all();
        }

        return $state;
    }

    protected function acquire(array $purchase): OwnershipResult
    {
        // Select the canonical purchase-line code from the fixture, then use the same entrypoint
        // as missing/foreign-source tests. No paid flag or caller-selected quantity is supplied.
        return $this->acquireCode($purchase['line']->code);
    }

    protected function acquireCode(string $lineCode, ?string $key = null): OwnershipResult
    {
        // Use the stable per-source key unless an idempotency case explicitly supplies another key.
        // The real service still resolves tenant, payment, quantity, cost and date from stored records.
        return app(OwnershipService::class)->acquirePurchaseLine($lineCode, $key ?? 'acquire-'.$lineCode);
    }

    protected function assertAcquisition(array $purchase, OwnershipResult $result, string $expectedCost = '12.3400', string $expectedDate = '2026-10-12'): AcquisitionLot
    {
        // Find the one lot belonging to this canonical purchase-line source in real storage.
        // This catches a result that accidentally returns an older lot when the item has several purchases.
        $lot = AcquisitionLot::query()->where('source_module', 'PURCHASING')->where('source_type', 'PurchaseOrderLine')
            ->where('source_code', $purchase['line']->code)->sole();
        $owned = OwnedItem::query()->findOrFail($lot->owned_item_id);
        $this->assertSame((int) $purchase['item']->id, (int) $owned->inventory_item_id);
        $this->assertSame($owned->code, $result->ownedItemCode);
        $this->assertSame([$lot->code], $result->lotCodes);
        $this->assertSame([], $result->receiptLinkCodes);
        $this->assertSame([], $result->serializedItemCodes);
        // Verify the immutable source snapshots as exact values. Expected date/cost are explicit
        // parameters so free purchases can use their order date and zero cost without weakening 06a.
        $this->assertSame($expectedDate, $lot->acquired_at->toDateString());
        $this->assertSame($purchase['line']->ordered_quantity, $lot->acquired_quantity);
        $this->assertSame($expectedCost, $lot->unit_cost_basis);
        $this->assertSame($purchase['order']->currency_code, $lot->currency_code);
        $this->assertAcquisitionMovement($lot, $result);

        return $lot;
    }

    protected function assertAcquisitionMovement(AcquisitionLot $lot, OwnershipResult $result): void
    {
        // Require exactly one movement for this newly acquired lot. Its owned delta equals the
        // acquired quantity, while reserved and pledged stay zero because acquisition does neither.
        $movement = OwnershipMovement::query()->where('acquisition_lot_id', $lot->id)->sole();
        $this->assertSame('ACQUISITION', $movement->movement_type);
        $this->assertSame($lot->acquired_quantity, $movement->quantity);
        $this->assertSame($lot->acquired_quantity, $movement->owned_delta);
        $this->assertSame('0.000', $movement->reserved_delta);
        $this->assertSame('0.000', $movement->pledged_delta);
        $this->assertSame('PURCHASING', $movement->source_module);
        $this->assertSame('PurchaseOrderLine', $movement->source_type);
        $this->assertSame($lot->source_code, $movement->source_code);
        // Recording time uses the frozen server clock, independently of the earlier settlement date.
        $this->assertSame(\Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), $movement->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame([$movement->code], $result->movementCodes);
    }

    protected function assertBalances(int $ownedItemId, string $quantity): void
    {
        // Sum the stored ledger for this tenant and owned item rather than trusting response totals.
        // This assertion is for acquisition: all quantity is owned and none is reserved or pledged.
        $rows = DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('owned_item_id', $ownedItemId);
        $this->assertSame($quantity, (string) (clone $rows)->sum('owned_delta'));
        $this->assertSame('0.000', (string) (clone $rows)->sum('reserved_delta'));
        $this->assertSame('0.000', (string) (clone $rows)->sum('pledged_delta'));
    }

    protected function assertNoStock(InventoryItem $item): void
    {
        // Check Inventory's real stock projection as well as the absence of receipt/movement/piece rows.
        // Owning paid goods must not make them physically present before a receipt records their arrival.
        $detail = app(InventoryService::class)->itemDetail($item->code);
        $this->assertSame('0.000', $detail->totalQuantity);
        $this->assertSame(0, $this->countRows('inventory_movements'));
        $this->assertSame(0, $this->countRows('inventory_serialized_items'));
        $this->assertSame(0, $this->countRows('purchase_receipts'));
    }

    protected function countRows(string $table): int
    {
        // Count only the active fixture tenant's rows. Other verification data may already exist
        // in the disposable database and must not influence this test's expected counts.
        return DB::table($table)->where('tenant_id', $this->ownershipFixture['tenant']->id)->count();
    }
    protected function assertRejected(string $lineCode, string $exceptionClass): void
    {
        // Capture both the acting tenant and any foreign fixture tenants before attempting acquisition.
        // A rejected cross-tenant source must not modify either side of the invalid association.
        $tenantIds = array_keys($this->ownershipFixtures);
        $before = $this->businessState($tenantIds);
        try {
            $this->acquireCode($lineCode);
            $this->fail('Ineligible source must not acquire Ownership.');
        } catch (ApiException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($exceptionClass === TenantNotFound::class ? 404 : 422, $exception->statusCode());
        }

        // Compare full row contents so an in-place balance, source or version update cannot hide
        // behind unchanged counts. Failed diagnostics may remain, but success/processing replays may not.
        $this->assertSame($before, $this->businessState($tenantIds));
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->whereIn('tenant_id', $tenantIds)
            ->where('idempotency_key', 'acquire-'.$lineCode)->whereIn('status', ['processing', 'completed'])->count());
    }

    protected function businessState(array $tenantIds): array
    {
        // Snapshot source, custody, Ownership and financial business rows in a stable ID order.
        // Exclude diagnostic/replay rows here: the rejection helper separately prohibits completed
        // or stranded processing requests while permitting the existing failed-diagnostic policy.
        $state = [];
        foreach (['purchase_orders', 'purchase_order_lines', 'purchase_payments', 'purchase_receipts', 'purchase_receipt_lines',
            'inventory_items', 'inventory_locations', 'inventory_movements', 'inventory_serialized_items', 'inventory_reservations',
            'owned_items', 'acquisition_lots', 'ownership_movements', 'currencies', 'financial_accounts',
            'financial_account_transactions', 'tenant_accounting_transactions'] as $table) {
            $state[$table] = DB::table($table)->whereIn('tenant_id', $tenantIds)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }
    protected function lotSnapshots(): array
    {
        // Capture every persisted lot column, keyed by its public code, in a stable order.
        // Full rows catch historical edits that a response DTO might omit or normalize away.
        return DB::table('acquisition_lots')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->orderBy('id')->get()->mapWithKeys(static fn (object $row): array => [$row->code => (array) $row])->all();
    }

    protected function linkReceipt(AcquisitionLot $lot, array $receipt, string $quantity, array $codes = [], ?string $key = null): OwnershipResult
    {
        // Link the actual receipt line to this lot using the current aggregate version and a stable
        // retry key. The service must validate receipt association; the helper does not seed a link.
        // Negative cross-tenant cases need the known fixture's version so the actual service rejects
        // its foreign public code. Limit this test-only lookup to exact recorded fixture tenants.
        $owned = OwnedItem::withoutGlobalScope('tenant')->whereIn('tenant_id', array_keys($this->ownershipFixtures))
            ->where('tenant_id', $lot->tenant_id)->findOrFail($lot->owned_item_id);
        $request = OwnershipReceiptLinkRequest::fromValidated([
            'purchase_receipt_line_code' => $receipt['receiptLine']->code, 'quantity' => $quantity,
            'serialized_item_codes' => $codes,
            'update_key' => (int) $owned->update_key,
        ], $key ?? 'lot-link-'.$receipt['receiptLine']->code);
        return app(OwnershipService::class)->linkReceipt($owned->code, $lot->code, $request);
    }

    protected function saleLine(AcquisitionLot $lot, InventoryItem $item, string $quantity = '2.000'): SaleOrderLine
    {
        // Persist only a confirmed sale order and line for the requested quantity in the lot's currency.
        // The Ownership service still validates that source; no sale payment, delivery or cash effect
        // is manufactured here, so this fixture cannot prove the complete Quick Sell workflow.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        $order = SaleOrder::query()->create(['tenant_id' => $tenantId, 'code' => 'LS'.bin2hex(random_bytes(6)),
            'status' => 'CONFIRMED', 'sold_at' => '2026-10-15', 'currency_code' => $lot->currency_code,
            'created_by' => $this->ownershipFixture['actor']->id]);

        return SaleOrderLine::query()->create(['tenant_id' => $tenantId, 'code' => 'LL'.bin2hex(random_bytes(6)),
            'sales_order_id' => $order->id, 'inventory_item_id' => $item->id, 'owned_item_id' => $lot->owned_item_id,
            'item_description' => $item->name, 'tracking_mode' => $item->tracking_mode,
            'unit_label' => $this->ownershipFixture['unit']->name, 'ordered_quantity' => $quantity, 'unit_price' => '20.00']);
    }
    protected function allocation(AcquisitionLot $lot, SaleOrderLine $line, array $codes = []): SaleDeliveryAllocation
    {
        // Inventory's sale issue requires persisted delivery-allocation provenance and the original
        // sale-line reservation. Create those source facts without posting sale income or costs.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        $delivery = SaleDelivery::query()->create(['tenant_id' => $tenantId, 'code' => 'UD'.bin2hex(random_bytes(6)),
            'sales_order_id' => $line->sales_order_id, 'delivered_at' => '2026-10-15',
            'created_by' => $this->ownershipFixture['actor']->id]);

        return SaleDeliveryAllocation::query()->create(['tenant_id' => $tenantId, 'code' => 'UA'.bin2hex(random_bytes(6)),
            'sales_delivery_id' => $delivery->id, 'sales_order_line_id' => $line->id, 'acquisition_lot_id' => $lot->id,
            'inventory_location_id' => $this->ownershipFixture['location']->id, 'quantity' => '1.000',
            'unit_cost' => $lot->unit_cost_basis, 'unit_price' => $line->unit_price, 'serialized_item_codes' => $codes]);
    }

}
