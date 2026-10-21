<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\OwnershipLotFilter;
use App\Exceptions\{ApiException, InvalidTenantRequest, TenantNotFound};
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipPieceMembership, OwnershipReceiptLink, OwnershipCommitment, OwnershipPieceCommitment};
use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;

/** Real receipt/link fixtures for OWN-07; proposed model fields await OWN-15 schema review. */
abstract class OwnershipReceiptTestCase extends OwnershipPurchaseTestCase
{
    protected function goods(string $mode = 'QUANTITY', string $quantity = '10.000', string $paid = '123.40'): array
    {
        // Acquire from actual paid source records, without seeding a lot, link, membership or stock.
        // Each test chooses when receipt occurs so owned and physically received states stay distinct.
        $purchase = $this->paidPurchase($this->createItem($mode), $quantity, $paid);
        $result = $this->acquire($purchase);
        $lot = $this->assertAcquisition($purchase, $result);

        return ['purchase' => $purchase, 'item' => $purchase['item'], 'lot' => $lot,
            'owned' => OwnedItem::query()->findOrFail($lot->owned_item_id)];
    }

    protected function receiptState(): array
    {
        // Include both acting and foreign fixture tenants, plus live Ownership link/membership rows.
        // Comparing full stored values catches in-place capacity/version edits as well as extra rows.
        $tenantIds = array_keys($this->ownershipFixtures);
        $state = $this->businessState($tenantIds);
        foreach ([OwnershipReceiptLink::class, OwnershipPieceMembership::class, OwnershipCommitment::class, OwnershipPieceCommitment::class] as $class) {
            $table = (new $class())->getTable();
            $state[$table] = DB::table($table)->whereIn('tenant_id', $tenantIds)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }
        // Source records are included too: a rejected quantity/link/return must not silently change
        // the caller's allocations or return allowance while leaving Ownership row counts unchanged.
        foreach (['sales_orders', 'sales_order_lines', 'sales_order_reservations', 'sales_deliveries',
            'sales_delivery_allocations', 'sales_returns', 'sales_return_lines', 'purchase_returns',
            'purchase_return_lines', 'tenant_audit_logs'] as $table) {
            $state[$table] = DB::table($table)->whereIn('tenant_id', $tenantIds)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    protected function assertLinkRejected(AcquisitionLot $lot, array $receipt, string $quantity, array $codes = [], string $exception = InvalidTenantRequest::class): void
    {
        // Always use a fresh request key so a prior replay cannot hide an invalid association or limit.
        // A rejected link must preserve custody already recorded by the successful receipt.
        $before = $this->receiptState();
        $key = 'reject-link-'.bin2hex(random_bytes(6));
        try {
            $this->linkReceipt($lot, $receipt, $quantity, $codes, $key);
            $this->fail('Invalid receipt link must reject.');
        } catch (ApiException $error) {
            $this->assertInstanceOf($exception, $error);
            $this->assertSame($exception === TenantNotFound::class ? 404 : 422, $error->statusCode());
        }
        $this->assertSame($before, $this->receiptState());
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->whereIn('tenant_id', array_keys($this->ownershipFixtures))
            ->where('idempotency_key', $key)->whereIn('status', ['completed', 'processing'])->count());
    }

    protected function lotData(array $goods): array
    {
        // Read the public lot projection from the real service, rather than calculating received or
        // awaiting quantities in the test. Only one source lot exists for these particular fixtures.
        $page = app(OwnershipService::class)->lotPage($goods['owned']->code, OwnershipLotFilter::fromValidated([]));
        $rows = array_column($page->toArray()['items'], null, 'code');

        return $rows[$goods['lot']->code];
    }

    protected function memberships(array $goods): array
    {
        // Query actual membership episodes, including ended history. No snapshot is reconstructed
        // from current Inventory custody because a sold piece must keep its original Ownership history.
        return OwnershipPieceMembership::query()->where('acquisition_lot_id', $goods['lot']->id)
            ->orderBy('id')->get()->map(static fn ($row): array => $row->getAttributes())->all();
    }

    protected function assertCurrentPieces(array $goods, array $codes): void
    {
        // Translate current membership IDs back to public piece codes and compare the exact set.
        // Ended episodes remain in storage but must not make the same piece currently owned twice.
        $actual = OwnershipPieceMembership::query()->where('acquisition_lot_id', $goods['lot']->id)
            ->whereNull('ended_at')->join('inventory_serialized_items as pieces',
                'pieces.id', '=', 'inventory_serialized_item_id')->orderBy('pieces.code')->pluck('pieces.code')->all();
        sort($codes);
        $this->assertSame($codes, $actual);
    }

    protected function assertOriginalAcquisition(array $goods, array $snapshot): void
    {
        // Linking must not acquire again or revise the purchase's original historical snapshot.
        // Zero-delta link history, if reviewed later, is not confused with another acquisition movement.
        $this->assertSame($snapshot, $this->lotSnapshots());
        $this->assertBalances((int) $goods['lot']->owned_item_id, $goods['purchase']['line']->ordered_quantity);
        $this->assertSame(1, DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $goods['lot']->id)->where('movement_type', 'ACQUISITION')->count());
    }
}
