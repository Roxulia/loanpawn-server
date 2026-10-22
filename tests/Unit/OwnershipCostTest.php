<?php

namespace Tests\Unit;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipAcquisitionCreate;
use App\Exceptions\InvalidTenantRequest;
use App\Support\OwnershipCost;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** OWN-05b: exact cost contracts; production changes belong to OWN-14 and OWN-16. */
class OwnershipCostTest extends TestCase
{
    #[DataProvider('validCosts')]
    public function test_cost_keeps_four_decimal_places(string|int $input, string $expected): void
    {
        // Normalize exact input without rounding or converting it to a float.
        $this->assertSame($expected, OwnershipCost::normalize($input));
    }

    #[DataProvider('validCosts')]
    public function test_optional_value_keeps_exact_cost(string|int $input, string $expected): void
    {
        // Optional values use the same bounds and precision as required costs.
        $this->assertSame($expected, OwnershipCost::normalizeOptional($input));
    }

    public function test_optional_value_can_be_null(): void
    {
        // Unknown estimated value remains distinct from an explicit zero valuation.
        $this->assertNull(OwnershipCost::normalizeOptional(null));
    }

    #[DataProvider('invalidCosts')]
    public function test_cost_rejects_invalid_value(mixed $input): void
    {
        // Reject invalid costs through the existing business exception contract.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipCost::normalize($input);
    }

    public function test_required_cost_rejects_null(): void
    {
        // Only the estimated value is nullable; missing required cost is invalid.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipCost::normalize(null);
    }

    #[DataProvider('invalidCosts')]
    public function test_optional_value_rejects_invalid_cost(mixed $input): void
    {
        // Optional does not mean malformed or overflowing values may be silently discarded.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipCost::normalizeOptional($input);
    }

    #[DataProvider('validCosts')]
    public function test_request_keeps_exact_costs(string|int $input, string $expected): void
    {
        // Exercise the real DTO factory rather than building a replacement DTO inside the test.
        $request = $this->request(['unit_cost_basis' => $input, 'estimated_unit_value' => $input]);
        $this->assertSame($expected, $request->unitCostBasis);
        $this->assertSame($expected, $request->estimatedUnitValue);

        // Check the public JSON boundary as well as the in-memory property types.
        $data = json_decode(json_encode($request->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $data['unit_cost_basis']);
        $this->assertSame($expected, $data['estimated_unit_value']);
    }

    public function test_request_keeps_missing_estimate_null(): void
    {
        // Omitting an estimate must not invent a zero value.
        $request = $this->request();
        $this->assertNull($request->estimatedUnitValue);
        $this->assertNull($request->toArray()['estimated_unit_value']);
    }

    public function test_request_keeps_explicit_null_estimate(): void
    {
        // Explicit null and an omitted estimate have the same optional-value meaning.
        $request = $this->request(['estimated_unit_value' => null]);
        $this->assertNull($request->estimatedUnitValue);
        $this->assertNull($request->toArray()['estimated_unit_value']);
    }

    #[DataProvider('invalidCosts')]
    public function test_request_rejects_invalid_cost(mixed $input): void
    {
        // The factory must not cast invalid cost text or unsupported types into a valid amount.
        $this->expectException(InvalidTenantRequest::class);
        $this->request(['unit_cost_basis' => $input]);
    }

    public function test_request_rejects_null_cost(): void
    {
        // Required cost cannot use the estimate's null allowance.
        $this->expectException(InvalidTenantRequest::class);
        $this->request(['unit_cost_basis' => null]);
    }

    #[DataProvider('invalidCosts')]
    public function test_request_rejects_invalid_estimate(mixed $input): void
    {
        // Keep required cost valid so this case isolates estimate validation.
        $this->expectException(InvalidTenantRequest::class);
        $this->request(['estimated_unit_value' => $input]);
    }

    public static function validCosts(): array
    {
        // Use literal DECIMAL(18,4) expectations to catch padding and precision loss.
        return [
            'integer zero' => [0, '0.0000'],
            'string zero' => ['0', '0.0000'],
            'integer cost' => [12, '12.0000'],
            'two decimal places' => ['12.34', '12.3400'],
            'four decimal places' => ['12.3456', '12.3456'],
            'smallest value' => ['0.0001', '0.0001'],
            'normalized text' => [' +00012.3400 ', '12.3400'],
            'large adjacent value' => ['99999999999999.9998', '99999999999999.9998'],
            'maximum cost' => ['99999999999999.9999', '99999999999999.9999'],
        ];
    }

    public static function invalidCosts(): array
    {
        // Floats cannot recover exact source digits and are outside this new contract.
        return [
            'negative' => ['-0.0001'],
            'too many decimal places' => ['12.34567'],
            'extra zero precision' => ['12.34000'],
            'rounding would overflow' => ['99999999999999.99999'],
            'one unit above whole-part limit' => ['100000000000000.0000'],
            'very large value' => ['999999999999999999999999999999999999'],
            'empty' => [''],
            'blank' => ['   '],
            'scientific notation' => ['1e3'],
            'thousands separator' => ['1,000.0000'],
            'missing whole digits' => ['.0001'],
            'missing fraction digits' => ['12.'],
            'text suffix' => ['12.34 MMK'],
            'finite float' => [12.34],
            'zero float' => [0.0],
            'infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
            'boolean true' => [true],
            'boolean false' => [false],
            'array' => [[]],
        ];
    }

    private function request(array $changes = []): OwnershipAcquisitionCreate
    {
        // Supply stable valid fields; each case changes only its cost or estimate input.
        return OwnershipAcquisitionCreate::fromValidated(array_replace([
            'inventory_item_code' => 'ITEM-COST-TEST',
            'quantity' => '1.000',
            'acquired_at' => '2026-10-21',
            'unit_cost_basis' => '12.3400',
            'currency_code' => 'MMK',
        ], $changes));
    }
}
