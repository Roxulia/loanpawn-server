<?php

namespace Tests\Support;

/** Reuse exact-tenant cleanup and worker shutdown for committed FIFO fixtures. */
abstract class CommittedOwnershipFifoTestCase extends OwnershipFifoTestCase
{
    use CommittedOwnershipFixture;

    protected bool $wrapFixtureTransaction = false;

    protected function worker(): OwnershipWorker
    {
        // Reuse the parent's tracked worker collection so teardown always stops FIFO subprocesses.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;

        return $this->workers[$tenantId] ??= new OwnershipFifoWorker($tenantId);
    }
}
