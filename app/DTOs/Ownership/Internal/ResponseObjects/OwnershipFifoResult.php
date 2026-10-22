<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

use App\DataObjects\BaseDataObject;

/** Exact allocation references valid only within the caller's still-active transaction. */
final class OwnershipFifoResult extends BaseDataObject
{
    public function __construct(
        public string $inventoryItemCode,
        public string $ownedItemCode,
        public string $quantity,
        public array $allocations,
    ) {
        // Rows carry copied references and snapshots; serial input is resolved to public piece codes.
    }
}
