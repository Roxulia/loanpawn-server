<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\InventoryModule\InventoryLocation;

/**
 * Exposes a location's public code, restrictions, and optimistic version.
 * Construction reads supplied attributes only and performs no relationship queries.
 */
final class InventoryLocationDetail extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public bool $isDefault,
        public bool $isActive,
        public bool $isSellable,
        public int $updateKey,
    ) {
        // Keep this response independent of mutable Eloquent models.
    }

    public static function fromModel(InventoryLocation $location): self
    {
        // Copy scalar attributes without exposing tenant or database identifiers.
        return new self(
            code: $location->code,
            name: $location->name,
            type: $location->type,
            isDefault: (bool) $location->is_default,
            isActive: (bool) $location->is_active,
            isSellable: (bool) $location->is_sellable,
            updateKey: (int) $location->update_key,
        );
    }
}
