<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

use App\DataObjects\RequestObjects\InventoryPageFilter;

use App\Exceptions\InvalidTenantRequest;

/** Shared Ownership pagination and optional query normalization; contains no tenant selection. */
class OwnershipPageFilter extends InventoryPageFilter
{
    public static function fromValidated(array $data): static
    {
        // Preserve the concrete history filter type while reusing Inventory's page bounds.
        return new static(page: (int) ($data['page'] ?? 1), perPage: (int) ($data['per_page'] ?? 25));
    }

    protected static function holding(string $holding): string
    {
        // Current/depleted are quantity filters; lifecycle labels do not establish availability.
        $holding = strtolower(trim($holding));
        if (! in_array($holding, ['all', 'current', 'depleted'], true)) {
            throw new InvalidTenantRequest();
        }

        return $holding;
    }

    protected static function search(?string $search): ?string
    {
        // Blank optional search means no text filter; retain the literal search string "0".
        $search = $search === null ? null : trim($search);
        if ($search !== null && mb_strlen($search) > 120) {
            throw new InvalidTenantRequest();
        }

        return $search === '' ? null : $search;
    }

    protected static function code(?string $code, int $maximum = 40): ?string
    {
        // Normalize an optional public reference without resolving tenant records here.
        if ($code === null) {
            return null;
        }
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > $maximum) {
            throw new InvalidTenantRequest();
        }

        return $code;
    }
}
