<?php

namespace Tests\Feature\TenantModule;

use App\Services\TenantModule\OwnershipService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipQuantityTestCase;

class OwnershipAtomicityTest extends OwnershipQuantityTestCase
{
    public function test_parent_rollback_removes_acquisition_receipt_and_link(): void
    {
        // Fail only after all three real mutations succeed. The surrounding business transaction
        // must remove receipt custody and local idempotency completion as well as Ownership rows.
        $purchase = $this->paidPurchase($this->createItem());
        $before = $this->receiptState();
        $failure = new \RuntimeException('Outer operation failed');
        try {
            DB::transaction(function () use ($purchase, $failure): void {
                $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
                $receipt = $this->receivePurchase($purchase, '10.000');
                $this->linkReceipt($lot, $receipt, '10.000');
                throw $failure;
            });
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame($before, $this->receiptState());
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('idempotency_key', 'acquire-'.$purchase['line']->code)->whereIn('status', ['processing', 'completed'])->count());
    }

    #[DataProvider('failureTables')]
    public function test_write_failure_rolls_back_and_same_key_can_retry(string $table): void
    {
        // Throw after a real INSERT/UPDATE at the selected boundary. An observed injection is
        // mandatory: a changed audit/completion implementation must not silently skip the failure.
        $purchase = $this->paidPurchase($this->createItem());
        $before = $this->receiptState();
        $failure = new \RuntimeException('Injected write failure');
        $armed = true;
        $observed = false;
        DB::listen(function (QueryExecuted $query) use ($table, &$armed, &$observed, $failure): void {
            if ($armed && preg_match('/^\s*(insert|update)\b/i', $query->sql)
                && str_contains($query->sql, $table)
                && ($table !== 'tenant_idempotency_keys' || in_array('completed', $query->bindings, true))) {
                $observed = true;
                throw $failure;
            }
        });
        try {
            $this->acquireCode($purchase['line']->code, 'failure-retry');
            $this->fail('Injected write must interrupt acquisition.');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        } finally {
            $armed = false;
        }
        $this->assertTrue($observed, 'The chosen mutation boundary was never reached.');
        $this->assertSame($before, $this->receiptState());
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('idempotency_key', 'failure-retry')->whereIn('status', ['processing', 'completed'])->count());
        $this->assertAcquisition($purchase, $this->acquireCode($purchase['line']->code, 'failure-retry'));
    }

    public function test_internal_replay_does_not_abort_its_caller(): void
    {
        // A lost response is represented by discarding the first result. Replay must return data
        // normally, allowing the caller's later write to commit, rather than throwing an HTTP response.
        $purchase = $this->paidPurchase($this->createItem());
        $first = $this->acquireCode($purchase['line']->code, 'lost-response');
        $before = $this->receiptState();
        DB::transaction(function () use ($purchase, $first): void {
            $again = $this->acquireCode($purchase['line']->code, 'lost-response');
            $this->assertSame($first->toArray(), $again->toArray());
            $purchase['order']->update(['note' => 'Caller continued after replay']);
        });
        $this->assertSame('Caller continued after replay', $purchase['order']->fresh()->note);
        // The caller deliberately changed its order; every Ownership and custody table stays identical.
        $after = $this->receiptState();
        unset($before['purchase_orders'], $after['purchase_orders']);
        $this->assertSame($before, $after);
    }

    public static function failureTables(): array
    {
        return ['movement' => ['ownership_movements'], 'audit' => ['tenant_audit_logs'],
            'completion' => ['tenant_idempotency_keys']];
    }
}
