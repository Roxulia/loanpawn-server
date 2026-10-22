<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes one received purchase line available for linking to its acquisition lot.
 * Exact received, linked, and remaining quantities are independent service-supplied facts.
 * A candidate is not authorization; services revalidate the source and capacity on submission.
 */
final class OwnershipReceiptCandidate extends BaseDataObject
{
    public function __construct(
        public string $purchaseReceiptLineCode,
        public string $purchaseOrderLineCode,
        public string $receivedAt,
        public string $inventoryItemCode,
        public string $receivedQuantity,
        public string $linkedQuantity,
        public string $remainingToLinkQuantity,
    ) {
        // Store already-resolved scalar facts; serialization performs no queries or lazy model access.
    }
}
