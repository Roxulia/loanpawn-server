<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Exceptions\InvalidTenantRequest;

/**
 * Defines bounded pagination shared by Inventory item, unit, and history reads.
 * Also serves directly as the movement-history filter.
 */
class InventoryPageFilter extends BaseDataObject
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
    ) {
        // Reject invalid limits instead of silently truncating a caller's requested page.
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(array $data): self
    {
        // Consume validated integer inputs and apply the agreed default page size.
        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 25),
        );
    }
}
