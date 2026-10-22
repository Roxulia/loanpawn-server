<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidTenantRequest;

final class OwnershipQuantity
{
    public static function normalize(mixed $quantity, string $trackingMode = 'QUANTITY'): string
    {
        // Reuse Inventory's exact operation bounds and tracking-mode rules.
        return InventoryQuantity::format(InventoryQuantity::parse($quantity, $trackingMode));
    }

    public static function normalizeBalance(string $quantity): string
    {
        // Keep DECIMAL(24,3) totals as strings because scaled totals can exceed PHP integers.
        if (! preg_match('/\A\+?([0-9]+)(?:\.([0-9]{1,3}))?\z/', trim($quantity), $parts)) {
            throw new InvalidTenantRequest(__('ownership.balance_invalid'));
        }

        // Remove leading zeros before enforcing the twenty-one whole-digit storage limit.
        $whole = ltrim($parts[1], '0');
        if (strlen($whole) > 21) {
            throw new InvalidTenantRequest(__('ownership.balance_invalid'));
        }

        return ($whole === '' ? '0' : $whole).'.'.str_pad($parts[2] ?? '', 3, '0');
    }

    public static function compare(string $left, string $right): int
    {
        // Validate both operands and compare unsigned scaled digit strings without numeric coercion.
        $left = ltrim(str_replace('.', '', self::normalizeBalance($left)), '0');
        $right = ltrim(str_replace('.', '', self::normalizeBalance($right)), '0');

        return (strlen($left) <=> strlen($right)) ?: (strcmp($left, $right) <=> 0);
    }
}
