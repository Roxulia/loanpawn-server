<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Reports one mutation's item version and affected public references.
 * For receipts, serializedItemCodes contains newly received units only.
 * Reservation-only operations can return no movement codes.
 */
final class InventoryMutationResult extends BaseDataObject
{
    /**
     * @param list<string> $movementCodes Movements appended by this operation only.
     * @param list<string> $serializedItemCodes Units affected by this operation only.
     */
    public function __construct(
        public string $inventoryItemCode,
        public int $updateKey,
        public string $quantity,
        public array $movementCodes = [],
        public array $serializedItemCodes = [],
        public ?string $reservationCode = null,
        public ?string $remainingQuantity = null,
    ) {
        // Store copied operation results without loading an item's complete unit collection.
    }
}
