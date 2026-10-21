<?php

namespace Tests\Support;

use LogicException;

class OwnershipWorker extends TestWorker
{
    protected function payload(string $action, array $payload): array
    {
        // Preserve the Ownership reference allowlist; never accept environment secrets.
        $allowed = ['items', 'owned_items', 'lots', 'purchase_orders', 'owned_item_code', 'lot_code',
            'quantity', 'source_code', 'wait', 'month', 'now', 'purchase_line', 'receipt_line',
            'codes', 'key', 'update_key', 'sources'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new LogicException('Unknown Ownership worker payload field.');
        }

        return parent::payload($action, $payload);
    }

    protected function script(): string
    {
        // Retain the existing executable entrypoint.
        return 'ownership_concurrency_worker.php';
    }

    protected function releaseMessage(): string
    {
        // Ownership holders validate the original continue message.
        return 'continue';
    }
}
