<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

/**
 * Contains an item's public identity, version, and exact decimal balance totals.
 * availableQuantity is sellable availability; no serialized-item collection is embedded.
 * Services supply precomputed balances and batched Catalog/unit attributes.
 */
class InventoryItemSummary extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $catalogItemCode,
        public string $trackingMode,
        public string $unitCode,
        public string $unitName,
        public string $quantityScale,
        public string $totalQuantity,
        public string $reservedQuantity,
        public string $availableQuantity,
        public int $updateKey,
    ) {
        // Retain decimal strings and copied scalar values without database access.
    }
}
