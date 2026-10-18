<?php

namespace App\DataObjects\RequestObjects;

use App\Exceptions\InvalidTenantRequest;

/**
 * Filters physical serialized units within an item-scoped read.
 * availableOnly excludes issued/reserved units; custody and sellability remain distinct.
 * Repository reads must use stable ID ordering.
 */
final class InventoryUnitFilter extends InventoryPageFilter
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        public ?string $search = null,
        public ?string $locationCode = null,
        public bool $availableOnly = false,
    ) {
        // Bound pagination and normalize identifier/location filters, retaining search "0".
        parent::__construct($page, $perPage);
        $this->search = $search === null ? null : trim($search);
        $this->locationCode = $locationCode === null ? null : trim($locationCode);
        if (($this->search !== null && mb_strlen($this->search) > 120)
            || ($this->locationCode !== null && ($this->locationCode === '' || mb_strlen($this->locationCode) > 40))) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(array $data): self
    {
        // Keep physical-unit filters separate from Catalog measurement-unit options.
        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 25),
            search: $data['search'] ?? null,
            locationCode: $data['location_code'] ?? null,
            availableOnly: (bool) ($data['available_only'] ?? false),
        );
    }
}
