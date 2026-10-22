<?php

namespace Tests\Support;

use App\Exceptions\ApiException;
use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Holds only locks obtained by the real allocator; no test-side stock prelocking. */
class OwnershipFifoTask extends OwnershipTask
{
    protected function validate(array $request): void
    {
        // Restrict committed worker mutations to known disposable fixtures and explicit FIFO actions.
        $fixture = DB::table('tenants')->where('id', $request['tenant'])->where('tenant_code', 'like', 'ot%')
            ->where('name', 'like', 'Ownership Test %')->exists();
        if (! $fixture || ! in_array($request['action'], ['fifo_apply', 'fifo_rollback'], true)
            || count($request['items'] ?? []) !== 1 || empty($request['sources'])) {
            throw new LogicException('Unknown FIFO fixture or worker action.');
        }
    }

    protected function execute(array $request): ?int
    {
        // Preserve domain diagnostics separately; the shared lifecycle still rolls back and reports failure.
        try {
            return $this->allocateAndApply($request);
        } catch (ApiException $error) {
            self::report(['state' => 'domain_rejection', 'error_code' => $error->errorCode(),
                'message' => $error->getMessage()]);
            throw $error;
        }
    }

    private function allocateAndApply(array $request): int
    {
        // The allocator must acquire stock locks itself and keep them until the caller ends its transaction.
        DB::beginTransaction();
        OwnershipFifoCaller::lockSources($request['sources']);
        $result = app(OwnershipService::class)->allocateFifo(
            $request['items'][0], $request['quantity'], $request['codes'] ?? []);
        self::report(['state' => 'locked']);
        $this->waitForRelease($request);
        OwnershipFifoCaller::apply($result->toArray(), $request['sources']);

        // Both branches perform real writes; the rollback branch proves their disappearance to the waiter.
        if ($request['action'] === 'fifo_rollback') {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return count($result->toArray()['allocations']);
    }
}
