<?php

namespace App\DataObjects\RequestObjects;

use App\Exceptions\InvalidTenantRequest;

/**
 * Filters paginated item summaries by text, tracking mode, and public location.
 * Repository reads must use stable name/id ordering and resolve the tenant internally.
 */
final class InventoryListFilter extends InventoryPageFilter
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        public ?string $search = null,
        public ?string $trackingMode = null,
        public ?string $locationCode = null,
    ) {
        // Validate pagination and normalize optional filters without truthiness checks.
        parent::__construct($page, $perPage);
        $this->search = $search === null ? null : trim($search);
        $this->trackingMode = $trackingMode === null ? null : strtoupper(trim($trackingMode));
        $this->locationCode = $locationCode === null ? null : trim($locationCode);
        if (($this->search !== null && mb_strlen($this->search) > 120)
            || ($this->locationCode !== null && ($this->locationCode === '' || mb_strlen($this->locationCode) > 40))
            || ($this->trackingMode !== null && ! in_array($this->trackingMode, [
                'QUANTITY',
                'UNIQUE',
                'SERIALIZED'
                ], true))) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(array $data): self
    {
        // Restrict hydrated filters to supported public query fields.
        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 25),
            search: $data['search'] ?? null,
            trackingMode: $data['tracking_mode'] ?? null,
            locationCode: $data['location_code'] ?? null,
        );
    }
}
