<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\BaseDataObject;
use InvalidArgumentException;

/**
 * Returns only the item version and references affected by one Ownership mutation.
 * Services select the affected records; this DTO never expands the item's full history.
 */
final class OwnershipResult extends BaseDataObject
{
    public function __construct(
        public string $ownedItemCode,
        public int $updateKey,
        public array $lotCodes = [],
        public array $movementCodes = [],
        public array $receiptLinkCodes = [],
        public array $serializedItemCodes = [],
    ) {
        // Keep reference groups as lists of public codes rather than raw model/array rows.
        foreach (['lotCodes', 'movementCodes', 'receiptLinkCodes', 'serializedItemCodes'] as $field) {
            foreach ($this->$field as $code) {
                if (! is_string($code)) {
                    throw new InvalidArgumentException('Ownership result references must be strings.');
                }
            }
            $this->$field = array_values($this->$field);
        }
    }
}
