<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

/** Bounded lot reads by saved currency and current/depleted ownership quantity. */
final class OwnershipLotFilter extends OwnershipPageFilter
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        public ?string $currencyCode = null,
        public string $holding = 'all',
    ) {
        // Filter historical currency snapshots without imposing active-currency acquisition checks.
        parent::__construct($page, $perPage);
        $currencyCode = self::code($currencyCode, 10);
        $this->currencyCode = $currencyCode === null ? null : strtoupper($currencyCode);
        $this->holding = self::holding($holding);
    }

    public static function fromValidated(array $data): static
    {
        // Hydrate only the reviewed lot filters and pagination fields.
        return new self(
            page: (int) ($data['page'] ?? 1), perPage: (int) ($data['per_page'] ?? 25),
            currencyCode: $data['currency_code'] ?? null, holding: $data['holding'] ?? 'all',
        );
    }
}
