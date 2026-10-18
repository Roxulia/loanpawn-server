<?php

namespace App\DataObjects\RequestObjects;

use App\Enums\InventoryOperationOwner;
use LogicException;

/**
 * Describes transaction/idempotency and primary audit ownership for stock work.
 * Trusted orchestration constructs this context; it must never be hydrated from HTTP input.
 * External side effects belong to the caller's outermost after-commit callbacks.
 */
final class InventoryOperationContext
{
    public function __construct(
        public readonly string $operation,
        public readonly InventoryOperationOwner $owner,
        public readonly int $responseCode = 200,
        public readonly ?string $auditAction = null,
        public readonly ?string $auditTargetType = null,
        public readonly ?int $auditTargetId = null,
    ) {
        // Reject incomplete internal configuration before reserving idempotency.
        if (trim($operation) === '' || $responseCode < 200 || $responseCode > 299) {
            throw new LogicException('Inventory operation configuration is invalid.');
        }
        if ($owner === InventoryOperationOwner::PublicInventory
            && (trim($auditAction ?? '') === '' || trim($auditTargetType ?? '') === '')) {
            throw new LogicException('Public Inventory operations require an audit action and target type.');
        }
    }
}
