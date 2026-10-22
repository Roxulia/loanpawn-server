<?php

namespace App\DataObjects\ResponseObjects;

/** Internal copied stock selection; the caller must retain the enclosing transaction's locks. */
final class InventoryOwnershipStock
{
    public function __construct(public array $locations, public array $pieces)
    {
        // Location quantities use integer thousandths; pieces retain exact public codes and identifiers.
    }
}
