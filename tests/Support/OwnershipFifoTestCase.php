<?php

namespace Tests\Support;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipFifoResult;
use App\Exceptions\{ApiException, InvalidTenantRequest, TenantNotFound};
use App\Models\InventoryModule\{InventoryItem, InventoryLocation};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;

/** Shared fixtures for the proposed allocator; these do not prove purchase acquisition. */
abstract class OwnershipFifoTestCase extends OwnershipQuantityTestCase
{
    protected function fifoGoods(
        ?InventoryItem $item = null,
        string $quantity = '3.000',
        string $date = '2026-10-10',
        array $serials = [],
        bool $received = true,
        bool $linked = true,
        ?InventoryLocation $location = null,
    ): array {
        // Seed a known paid-source acquisition ledger using the existing baseline fixture.
        // Real receipt and linking services establish custody/membership, not fabricated availability.
        $item ??= $this->createItem($serials === [] ? 'QUANTITY' : 'SERIALIZED');
        $purchase = $this->createPurchase($item, $quantity, '0.00', null);
        $baseline = $this->createLot($purchase);
        $baseline['lot']->update(['acquired_at' => $date]);
        $goods = ['item' => $item, 'purchase' => $purchase, 'owned' => $baseline['ownedItem'],
            'lot' => $baseline['lot'], 'location' => $location ?? $this->ownershipFixture['location'],
            'serials' => $serials, 'pieces' => []];
        if (! $received) {
            return $goods;
        }

        // Temporarily choose the receipt destination, restoring fixture context even on failure.
        $original = $this->ownershipFixture['location'];
        try {
            $this->ownershipFixture['location'] = $goods['location'];
            $receipt = $this->receivePurchase($purchase, $quantity, $serials);
        } finally {
            $this->ownershipFixture['location'] = $original;
        }
        $goods['receipt'] = $receipt;
        $goods['pieces'] = $receipt['inventory']['serialized_item_codes'];
        if ($linked) {
            $this->linkReceipt($goods['lot'], $receipt, $quantity, $goods['pieces']);
        }

        return $goods;
    }

    protected function fifoLocation(): InventoryLocation
    {
        // An additional active shop allows deterministic multi-location allocation assertions.
        return InventoryLocation::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'code' => 'FL'.bin2hex(random_bytes(6)), 'name' => 'FIFO second shop', 'type' => 'SHOP',
            'is_default' => false, 'is_active' => true, 'is_sellable' => true, 'update_key' => 0]);
    }

    protected function fifo(string $itemCode, mixed $quantity, array $serials = []): array
    {
        // The real service must lock and allocate; this adapter never implements FIFO itself.
        $result = app(OwnershipService::class)->allocateFifo($itemCode, $quantity, $serials);
        $this->assertInstanceOf(OwnershipFifoResult::class, $result);

        return $result->toArray();
    }

    protected function assertFifo(array $goods, mixed $quantity, array $expected, array $serials = []): array
    {
        // Successful selection may hold locks, but may not create reservations or change business rows.
        $before = $this->fifoState();
        $result = DB::transaction(fn () => $this->fifo($goods['item']->code, $quantity, $serials));
        $this->assertSame(['inventory_item_code', 'owned_item_code', 'quantity', 'allocations'], array_keys($result));
        $this->assertSame($goods['item']->code, $result['inventory_item_code']);
        $this->assertSame($goods['owned']->code, $result['owned_item_code']);
        $this->assertSame($quantity, $result['quantity']);
        $this->assertSame($expected, $result['allocations']);
        $this->assertSame($before, $this->fifoState());

        return $result;
    }

    protected function fifoRow(array $goods, string $quantity, ?array $pieces = null): array
    {
        // Literal expected allocations retain exact cost/currency snapshots and public piece codes.
        return ['lot_code' => $goods['lot']->code, 'location_code' => $goods['location']->code,
            'quantity' => $quantity, 'serialized_item_codes' => $pieces ?? $goods['pieces'],
            'unit_cost_basis' => $goods['lot']->unit_cost_basis, 'currency_code' => $goods['lot']->currency_code];
    }

    protected function rejectFifo(string $itemCode, mixed $quantity, array $serials, string $message,
        string $exception = InvalidTenantRequest::class): void
    {
        // Catch only domain exceptions: missing methods, DTOs or schema must remain visible failures.
        $before = $this->fifoState();
        try {
            DB::transaction(fn () => $this->fifo($itemCode, $quantity, $serials));
            $this->fail('Invalid FIFO allocation must reject completely.');
        } catch (ApiException $error) {
            $this->assertSame($exception, get_class($error));
            $this->assertSame($exception === TenantNotFound::class ? 404 : 422, $error->statusCode());
            $this->assertSame($exception === TenantNotFound::class ? 'TENANT_NOT_FOUND' : 'INVALID_TENANT_REQUEST', $error->errorCode());
            $this->assertSame(__($message), $error->getMessage());
        }
        $this->assertSame($before, $this->fifoState());
    }

    protected function fifoState(): array
    {
        // Allocation owns no replay or checkpoint writes either; compare those alongside all business rows.
        $state = $this->receiptState();
        foreach (['tenant_idempotency_keys', 'ownership_monthly_checkpoints', 'ownership_monthly_checkpoint_balances'] as $table) {
            $state[$table] = DB::table($table)->whereIn('tenant_id', array_keys($this->ownershipFixtures))
                ->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    protected function fifoSources(array $goods, string $quantity): array
    {
        // Persist real caller sources before allocation; they are not extra allocator arguments.
        $sources = [];
        foreach ($goods as $row) {
            $sources[$row['lot']->code] = $this->sale($row, $quantity);
        }

        return $sources;
    }

    protected function assertFifoBalances(array $goods, string $quantity): void
    {
        // Independent SQL totals must agree at both item and lot levels, as well as physical custody.
        $this->assertLotBalance($goods, $quantity);
        $this->assertBalances((int) $goods['owned']->id, $quantity);
        $this->assertSame($quantity, app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
    }
}
