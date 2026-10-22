<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidTenantRequest;

final class OwnershipCost
{
    public static function normalize(mixed $cost): string
    {
        // Require exact source digits; floats and implicit boolean/null conversions lose that contract.
        if ((! is_string($cost) && ! is_int($cost))
            || ! preg_match('/\A\+?([0-9]+)(?:\.([0-9]{1,4}))?\z/', trim((string) $cost), $parts)) {
            throw new InvalidTenantRequest(__('ownership.cost_invalid'));
        }

        // Match DECIMAL(18,4) without rounding, integer scaling, or floating-point conversion.
        $whole = ltrim($parts[1], '0');
        if (strlen($whole) > 14) {
            throw new InvalidTenantRequest(__('ownership.cost_invalid'));
        }

        return ($whole === '' ? '0' : $whole).'.'.str_pad($parts[2] ?? '', 4, '0');
    }

    public static function normalizeOptional(mixed $cost): ?string
    {
        // Preserve unknown estimated values while validating every supplied value identically.
        return $cost === null ? null : self::normalize($cost);
    }
}
