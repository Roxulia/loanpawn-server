<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Describes one retained Ownership movement without exposing internal database IDs.
 * Quantity and signed deltas remain decimal strings, e.g. ownedDelta "-1.000".
 * Occurrence and recording timestamps are separate facts supplied by the service.
 */
final class OwnershipMovementRow extends BaseDataObject
{
    public function __construct(
        public string $code,
        public ?string $lotCode,
        public string $operation,
        public string $quantity,
        public string $ownedDelta,
        public string $reservedDelta,
        public string $pledgedDelta,
        public ?string $sourceModule,
        public ?string $sourceType,
        public ?string $sourceCode,
        public string $occurredAt,
        public ?string $recordedAt,
    ) {
        // Store already-resolved scalar facts; serialization performs no queries or lazy model access.
    }
}
