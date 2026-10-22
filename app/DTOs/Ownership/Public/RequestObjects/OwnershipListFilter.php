<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

use App\Exceptions\InvalidTenantRequest;

/** Bounded item search by text, tracking mode, and current/depleted holdings. */
final class OwnershipListFilter extends OwnershipPageFilter
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        public ?string $search = null,
        public ?string $trackingMode = null,
        public string $holding = 'all',
    ) {
        // Keep historical holdings visible by default and validate only supported public filters.
        parent::__construct($page, $perPage);
        $this->search = self::search($search);
        $this->holding = self::holding($holding);
        $this->trackingMode = $trackingMode === null ? null : strtoupper(trim($trackingMode));
        if ($this->trackingMode !== null && ! in_array($this->trackingMode, ['QUANTITY', 'UNIQUE', 'SERIALIZED'], true)) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(array $data): static
    {
        // Ignore unrelated query fields rather than hydrating source or tenant authority.
        return new self(
            page: (int) ($data['page'] ?? 1), perPage: (int) ($data['per_page'] ?? 25),
            search: $data['search'] ?? null, trackingMode: $data['tracking_mode'] ?? null,
            holding: $data['holding'] ?? 'all',
        );
    }
}
