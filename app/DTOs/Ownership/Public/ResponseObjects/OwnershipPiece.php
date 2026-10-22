<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes one serialized piece candidate using public identity and current location.
 * An unknown identifier remains null; the identifier "0" is retained as text.
 * Link/eligibility state is supplied by the service and must be rechecked before mutation.
 */
final class OwnershipPiece extends BaseDataObject
{
    public function __construct(
        public string $code,
        public ?string $identifier,
        public string $purchaseReceiptLineCode,
        public ?string $locationCode,
        public ?string $locationName,
        public string $linkState,
        public bool $isEligible,
    ) {
        // Store already-resolved scalar facts; serialization performs no queries or lazy model access.
    }
}
