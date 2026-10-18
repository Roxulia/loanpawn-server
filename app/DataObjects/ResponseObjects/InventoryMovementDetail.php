<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Exposes immutable movement provenance through public business references.
 * Quantity remains an exact decimal string and occurredAt is an ISO 8601 timestamp.
 * History reads must sort by occurred_at and ID for stable pagination.
 */
final class InventoryMovementDetail extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $inventoryItemCode,
        public ?string $inventoryUnitCode,
        public string $type,
        public string $quantity,
        public ?InventoryLocationDetail $fromLocation,
        public ?InventoryLocationDetail $toLocation,
        public ?string $reason,
        public ?string $sourceType,
        public ?string $sourceCode,
        public ?string $createdByCode,
        public string $occurredAt,
    ) {
        // Retain actor/source provenance without exposing database identifiers or raw models.
    }
}
