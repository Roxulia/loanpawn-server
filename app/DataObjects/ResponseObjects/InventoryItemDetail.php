<?php

namespace App\DataObjects\ResponseObjects;

use InvalidArgumentException;

/**
 * Extends an item summary with typed per-location balances.
 * Physical serialized units are retrieved separately through their paginated contract.
 */
final class InventoryItemDetail extends InventoryItemSummary
{
    /** @var list<InventoryLocationBalance> */
    public array $locations;

    /** @param list<InventoryLocationBalance> $locations */
    public function __construct(InventoryItemSummary $summary, array $locations = [])
    {
        // Copy the bounded summary fields without keeping a mutable model reference.
        parent::__construct(
            code: $summary->code,
            name: $summary->name,
            description: $summary->description,
            catalogItemCode: $summary->catalogItemCode,
            trackingMode: $summary->trackingMode,
            unitCode: $summary->unitCode,
            unitName: $summary->unitName,
            quantityScale: $summary->quantityScale,
            totalQuantity: $summary->totalQuantity,
            reservedQuantity: $summary->reservedQuantity,
            availableQuantity: $summary->availableQuantity,
            updateKey: $summary->updateKey,
        );

        // Require typed location rows so serialization cannot leak raw models or arrays.
        foreach ($locations as $location) {
            if (! $location instanceof InventoryLocationBalance) {
                throw new InvalidArgumentException('Expected InventoryLocationBalance entries.');
            }
        }
        $this->locations = array_values($locations);
    }
}
