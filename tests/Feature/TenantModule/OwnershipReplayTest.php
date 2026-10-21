<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\ResponseObjects\OwnershipResult;
use App\Exceptions\{ApiException, IdempotencyKeyConflict, InvalidTenantRequest};
use App\Models\CoreModule\TenantIdempotencyKey;
use App\Models\OwnershipModule\AcquisitionLot;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipPurchaseTestCase;

/** OWN-06d: stored replay results and source uniqueness through the real acquisition service. */
class OwnershipReplayTest extends OwnershipPurchaseTestCase
{
    public function test_same_key_returns_original_result_without_new_effects(): void
    {
        // Complete one real paid-source acquisition before taking the retry snapshot.
        // The snapshot includes the first lot, movement, balances, versions and payment records.
        $purchase = $this->paidPurchase($this->createItem());
        $key = 'same-request';
        $first = $this->acquireCode($purchase['line']->code, $key);
        $lot = $this->assertAcquisition($purchase, $first);
        $this->assertStoredResult($key, $first);
        $before = $this->businessState(array_keys($this->ownershipFixtures));
        $replays = $this->replayRows();

        // Internal replay must return a typed value; an HTTP replay exception would fail this test.
        // Rebuilding a fresh result or repeating the acquisition must not replace the saved response.
        $retry = $this->acquireCode($purchase['line']->code, $key);

        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertSame($before, $this->businessState(array_keys($this->ownershipFixtures)));
        $this->assertSame($replays, $this->replayRows());
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('ownership_movements'));
    }

    public function test_new_key_cannot_acquire_the_same_source_twice(): void
    {
        // A fresh idempotency key is a new request, but it does not make the same purchase line
        // a new acquisition source. The already completed lot and its replay must remain intact.
        $purchase = $this->paidPurchase($this->createItem());
        $first = $this->acquireCode($purchase['line']->code, 'first-acquisition');
        $lot = $this->assertAcquisition($purchase, $first);

        $this->assertRequestRejected($purchase['line']->code, 'second-acquisition', InvalidTenantRequest::class);

        $this->assertStoredResult('first-acquisition', $first);
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(1, $this->countRows('ownership_movements'));
    }

    public function test_same_key_with_another_source_conflicts(): void
    {
        // Both sources are independently paid and eligible. Only the reused request key is wrong,
        // so failure cannot be attributed to an unpaid or invalid second source.
        $item = $this->createItem();
        $firstPurchase = $this->paidPurchase($item);
        $secondPurchase = $this->paidPurchase($item);
        $key = 'fixed-intent';
        $first = $this->acquireCode($firstPurchase['line']->code, $key);
        $lot = $this->assertAcquisition($firstPurchase, $first);

        // Changing the purchase-line code changes caller intent. Expect a 409 conflict rather than
        // replaying the first purchase as if it were the second, or overwriting the completed record.
        $this->assertRequestRejected($secondPurchase['line']->code, $key, IdempotencyKeyConflict::class);

        $this->assertStoredResult($key, $first);
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(0, AcquisitionLot::query()->where('source_module', 'PURCHASING')
            ->where('source_type', 'PurchaseOrderLine')->where('source_code', $secondPurchase['line']->code)->count());
    }

    public function test_new_purchase_returns_its_new_lot_not_an_older_lot(): void
    {
        // Two purchases explicitly reuse one item, so its history will contain two acquisition lots.
        // The second operation must identify its own source lot instead of selecting history[0].
        $item = $this->createItem();
        $firstPurchase = $this->paidPurchase($item);
        $secondPurchase = $this->paidPurchase($item);
        $first = $this->acquireCode($firstPurchase['line']->code, 'purchase-one');
        $firstLot = $this->assertAcquisition($firstPurchase, $first);

        $second = $this->acquireCode($secondPurchase['line']->code, 'purchase-two');
        $secondLot = $this->assertAcquisition($secondPurchase, $second);

        $this->assertSame($first->ownedItemCode, $second->ownedItemCode);
        $this->assertNotSame($firstLot->code, $secondLot->code);
        $this->assertSame([$secondLot->code], $second->lotCodes);
        $this->assertNotSame($first->movementCodes, $second->movementCodes);
        $this->assertBalances((int) $secondLot->owned_item_id, '20.000');
        $this->assertSame(2, $this->countRows('acquisition_lots'));
        $this->assertSame(2, $this->countRows('ownership_movements'));
    }

    public function test_old_key_replays_original_result_after_later_acquisition(): void
    {
        // A later purchase changes the same item's current aggregate/version. The old key still
        // represents the first operation and must retain its original version and affected codes.
        $item = $this->createItem();
        $firstPurchase = $this->paidPurchase($item);
        $secondPurchase = $this->paidPurchase($item);
        $first = $this->acquireCode($firstPurchase['line']->code, 'older-request');
        $this->assertAcquisition($firstPurchase, $first);
        $second = $this->acquireCode($secondPurchase['line']->code, 'later-request');
        $this->assertAcquisition($secondPurchase, $second);
        $before = $this->businessState(array_keys($this->ownershipFixtures));
        $replays = $this->replayRows();

        $retry = $this->acquireCode($firstPurchase['line']->code, 'older-request');

        // Compare the entire original typed result, including update_key, rather than rebuilding it
        // from the current item. Replaying an old result must not lower the stored current version.
        $this->assertSame($first->toArray(), $retry->toArray());
        $this->assertNotSame($second->lotCodes, $retry->lotCodes);
        $this->assertSame($before, $this->businessState(array_keys($this->ownershipFixtures)));
        $this->assertSame($replays, $this->replayRows());
        $this->assertStoredResult('older-request', $first);
    }

    private function assertStoredResult(string $key, OwnershipResult $result): void
    {
        // Inspect the real completed record for this tenant/key. MySQL may reorder JSON object
        // keys, so compare decoded stored fields by value; typed replay equality is checked separately.
        $record = TenantIdempotencyKey::query()->where('idempotency_key', $key)->sole();
        $this->assertSame('completed', $record->status);
        $this->assertEquals($result->toArray(), $record->response_body['data']);
    }

    private function replayRows(): array
    {
        // Failed diagnostics may be retained after a rejected new request. Completed and processing
        // records are compared in full so a new success, stranded request or changed old result is caught.
        return DB::table('tenant_idempotency_keys')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->whereIn('status', ['completed', 'processing'])->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    private function assertRequestRejected(string $lineCode, string $key, string $exceptionClass): void
    {
        // Take the snapshot after source setup and the first acquisition, not before. This requires
        // preserving existing legitimate ownership as well as avoiding any new effects on rejection.
        $before = $this->businessState(array_keys($this->ownershipFixtures));
        $replays = $this->replayRows();
        try {
            $this->acquireCode($lineCode, $key);
            $this->fail('Repeated or conflicting acquisition must reject.');
        } catch (ApiException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($exceptionClass === IdempotencyKeyConflict::class ? 409 : 422, $exception->statusCode());
        }

        // This includes stored cash/accounting/Inventory state, immutable lots and aggregate versions.
        // An error must not add business effects or invalidate the original successful replay record.
        $this->assertSame($before, $this->businessState(array_keys($this->ownershipFixtures)));
        $this->assertSame($replays, $this->replayRows());
    }
}
