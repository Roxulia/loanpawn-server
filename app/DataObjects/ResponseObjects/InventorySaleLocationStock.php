<?php

namespace App\DataObjects\ResponseObjects;

use App\Models\InventoryModule\InventoryLocation;

/** Internal locked allocation reference; never serialize this model through HTTP. */
final class InventorySaleLocationStock
{
    public function __construct(public readonly InventoryLocation $location, public readonly int $availableThousandths)
    {
        // Quantities are scaled integers; the caller retains its owning transaction.
    }
}
