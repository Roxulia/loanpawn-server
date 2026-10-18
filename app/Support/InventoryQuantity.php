<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidTenantRequest;

/**
 * Converts inventory quantities to integer thousandths for exact comparisons
 * and formats those integers as decimal strings for storage or responses.
 *
 * Parsing validates positive quantities, a maximum of three decimal places,
 * the DECIMAL(14, 3) storage range, and UNIQUE or SERIALIZED tracking rules.
 * Stored-balance parsing, formatting, and comparison accept zero for exhausted stock.
 * Invalid quantities raise InvalidTenantRequest with localized messages.
 */
final class InventoryQuantity
{
    public const SCALE = 1000;

    // Match the maximum positive value stored by DECIMAL(14, 3).
    public const MAX_THOUSANDTHS = 99_999_999_999_999;

    /** Parse a positive input quantity without floating-point arithmetic. */
    public static function parse(mixed $quantity, string $trackingMode = 'QUANTITY'): int
    {
        // Normalize supported inputs, preserving float precision before parsing digits.
        $decimal = self::normalizeInput($quantity);
        if (! preg_match('/\A([+-]?)([0-9]+)(?:\.([0-9]+))?\z/', $decimal, $parts)) {
            throw new InvalidTenantRequest(__('inventory.quantity_invalid'));
        }

        // Validate precision before constructing a bounded integer quantity.
        $fraction = $parts[3] ?? '';
        if (strlen($fraction) > 3) {
            throw new InvalidTenantRequest(__('inventory.quantity_precision'));
        }
        $digits = ltrim($parts[2].str_pad($fraction, 3, '0'), '0');
        if ($parts[1] === '-' || $digits === '') {
            throw new InvalidTenantRequest(__('inventory.quantity_positive'));
        }

        // Compare digit strings before casting so oversized input cannot overflow PHP integers.
        $maximum = (string) self::MAX_THOUSANDTHS;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidTenantRequest(__('inventory.quantity_out_of_range'));
        }
        $thousandths = (int) $digits;

        // Apply tracking-mode constraints to the exact integer representation.
        $trackingMode = strtoupper(trim($trackingMode));
        if (! in_array($trackingMode, ['QUANTITY', 'UNIQUE', 'SERIALIZED'], true)) {
            throw new InvalidTenantRequest();
        }
        if ($trackingMode === 'UNIQUE' && $thousandths !== self::SCALE) {
            throw new InvalidTenantRequest(__('inventory.unique_quantity'));
        }
        if ($trackingMode === 'SERIALIZED' && $thousandths % self::SCALE !== 0) {
            throw new InvalidTenantRequest(__('inventory.serialized_quantity'));
        }

        return $thousandths;
    }

    /** Convert a non-negative stored balance to thousandths, including exact exhaustion. */
    public static function parseBalance(string $quantity): int
    {
        // Accept exact zero without weakening the positive mutation-input parser.
        if (preg_match('/\A[+]?0+(?:\.0{1,3})?\z/', trim($quantity))) {
            return 0;
        }

        // Reuse precision and range checks for every non-zero balance.
        return self::parse($quantity);
    }

    /** Format integer thousandths; zero is permitted for exhausted stock balances. */
    public static function format(int $thousandths): string
    {
        // Validate internal balances and format using integer division and remainder only.
        self::assertScaledRange($thousandths);

        return intdiv($thousandths, self::SCALE).'.'.str_pad(
            (string) ($thousandths % self::SCALE),
            3,
            '0',
            STR_PAD_LEFT,
        );
    }

    /** Compare scaled quantities exactly, returning -1, 0, or 1. */
    public static function compare(int $leftThousandths, int $rightThousandths): int
    {
        // Permit zero availability while rejecting invalid internal scaled balances.
        self::assertScaledRange($leftThousandths);
        self::assertScaledRange($rightThousandths);

        return $leftThousandths <=> $rightThousandths;
    }

    private static function normalizeInput(mixed $quantity): string
    {
        // Accept decimal strings and integers without coercing booleans, null, or objects.
        if (is_string($quantity) || is_int($quantity)) {
            return trim((string) $quantity);
        }
        if (! is_float($quantity) || ! is_finite($quantity)) {
            throw new InvalidTenantRequest(__('inventory.quantity_invalid'));
        }

        // JSON preserves a float's serialized digits without rounding it to three places.
        $decimal = strtolower((string) json_encode($quantity, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if (! str_contains($decimal, 'e')) {
            return $decimal;
        }

        // Expand scientific notation from numeric inputs using string operations only.
        [$mantissa, $exponent] = explode('e', $decimal, 2);
        $sign = str_starts_with($mantissa, '-') ? '-' : '';
        $pieces = explode('.', ltrim($mantissa, '+-'), 2);
        $digits = $pieces[0].($pieces[1] ?? '');
        $position = strlen($pieces[0]) + (int) $exponent;
        if ($position <= 0) {
            return $sign.'0.'.str_repeat('0', -$position).$digits;
        }
        if ($position >= strlen($digits)) {
            return $sign.$digits.str_repeat('0', $position - strlen($digits));
        }

        return $sign.substr($digits, 0, $position).'.'.substr($digits, $position);
    }

    private static function assertScaledRange(int $thousandths): void
    {
        // Parsed mutation quantities are positive; internal comparison/formatting also accepts zero.
        if ($thousandths < 0) {
            throw new InvalidTenantRequest(__('inventory.quantity_positive'));
        }
        if ($thousandths > self::MAX_THOUSANDTHS) {
            throw new InvalidTenantRequest(__('inventory.quantity_out_of_range'));
        }
    }
}
