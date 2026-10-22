<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes an acquisition lot's historical source/cost snapshots and current quantities.
 * Amounts are decimal strings in currencyCode, e.g. unitCostBasis "125000.1234".
 * estimatedUnitValue may be null when unknown. No currency totals or physical pieces are embedded.
 */
final class OwnershipLot extends BaseDataObject
{
    public function __construct(
        public string $code,
        public ?string $sourceModule,
        public ?string $sourceType,
        public ?string $sourceCode,
        public string $acquiredAt,
        public string $acquiredQuantity,
        public string $ownedQuantity,
        public string $reservedQuantity,
        public string $pledgedQuantity,
        public string $availableQuantity,
        public string $receivedBackedQuantity,
        public string $awaitingReceiptQuantity,
        public string $pendingLinkQuantity,
        public string $unitCostBasis,
        public ?string $estimatedUnitValue,
        public string $currencyCode,
        public ?string $description,
    ) {
        // Store already-resolved scalar facts; serialization performs no queries or lazy model access.
    }
}
