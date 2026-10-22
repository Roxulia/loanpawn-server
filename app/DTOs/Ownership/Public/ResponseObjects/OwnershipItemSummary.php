<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes one bounded Ownership register row using precomputed scalar facts.
 * Owned availability, physical custody, received backing, and pending receipt/link quantities
 * are distinct dimensions. Images reuse Inventory URLs; lots and history are separately paginated.
 */
class OwnershipItemSummary extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $inventoryItemCode,
        public ?string $catalogItemCode,
        public string $name,
        public ?string $description,
        public string $trackingMode,
        public ?string $unitCode,
        public ?string $unitName,
        public string $quantityScale,
        public string $ownedQuantity,
        public string $reservedQuantity,
        public string $pledgedQuantity,
        public string $availableQuantity,
        public string $physicalQuantity,
        public string $receivedBackedQuantity,
        public string $awaitingReceiptQuantity,
        public string $pendingLinkQuantity,
        public int $lotCount,
        public string $lifecycleStatus,
        public int $updateKey,
        public ?string $imageUrl,
        public ?string $imageUrlExpiresAt,
    ) {
        // Store already-resolved scalar facts; serialization performs no queries or lazy model access.
    }
}
