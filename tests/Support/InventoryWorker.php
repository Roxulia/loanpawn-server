<?php

namespace Tests\Support;

class InventoryWorker extends TestWorker
{
    protected function script(): string
    {
        // Retain the existing executable entrypoint.
        return 'inventory_concurrency_worker.php';
    }

    protected function releaseMessage(): string
    {
        // Inventory holders use the original go message.
        return 'go';
    }
}
