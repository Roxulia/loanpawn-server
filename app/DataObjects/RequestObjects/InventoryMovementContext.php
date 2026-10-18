<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

/**
 * Carries trusted movement provenance without mixing internal metadata into request arrays.
 * The caller's transaction owns the idempotency record; request inputs cannot choose its ID.
 */
final class InventoryMovementContext extends BaseDataObject
{
    public function __construct(
        public ?string $reason = null,
        public ?string $sourceType = null,
        public ?string $sourceCode = null,
        public ?int $idempotencyRecordId = null,
    ) {
        // Preserve nullable provenance; Inventory assigns actor and recording timestamps at append.
    }
}
