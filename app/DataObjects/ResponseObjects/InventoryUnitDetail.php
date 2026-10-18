<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes one serialized identity and its latest physical custody.
 * A null location means no current custody; isAvailable means in custody and unreserved.
 * Active/sellable location flags are exposed separately for callers.
 */
final class InventoryUnitDetail extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $inventoryItemCode,
        public ?string $identifier,
        public ?InventoryLocationDetail $location,
        public bool $isReserved,
        public bool $isAvailable,
        public ?string $latestMovementCode,
    ) {
        // Accept only supplied, batched custody data; never load movement relationships.
    }
}
