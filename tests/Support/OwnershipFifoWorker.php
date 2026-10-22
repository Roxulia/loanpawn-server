<?php

namespace Tests\Support;

/** FIFO payload uses the existing Ownership allowlist and release protocol. */
class OwnershipFifoWorker extends OwnershipWorker
{
    protected function script(): string
    {
        // Separate executable actions avoid changing existing Ownership concurrency scenarios.
        return 'ownership_fifo_worker.php';
    }
}
