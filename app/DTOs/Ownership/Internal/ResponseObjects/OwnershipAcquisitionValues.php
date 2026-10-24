<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

/** Exact acquisition snapshots already validated against the actual purchase and Inventory identity. */
final readonly class OwnershipAcquisitionValues
{
    public function __construct(public string $quantity, public string $unitCost) {}
}
