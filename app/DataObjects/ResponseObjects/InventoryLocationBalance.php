<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Combines a location's restrictions/version with exact item balances.
 * availableQuantity represents unreserved physical stock at this location;
 * sale eligibility additionally requires its active and sellable flags.
 */
final class InventoryLocationBalance extends BaseDataObject
{
    public function __construct(
        public InventoryLocationDetail $location,
        public string $quantity,
        public string $reservedQuantity,
        public string $availableQuantity,
    ) {
        // Store the supplied balance projection without querying movement history.
    }
}
