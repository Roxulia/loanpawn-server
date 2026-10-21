<?php

namespace Tests\Unit;

use App\Exceptions\InvalidTenantRequest;
use App\Support\OwnershipQuantity;
use PHPUnit\Framework\Attributes\{DataProvider, DataProviderExternal};
use Tests\TestCase;

/** OWN-05a: test-first Ownership quantity contract; implementation belongs to OWN-14. */
class OwnershipQuantityTest extends TestCase
{
    #[DataProviderExternal(InventoryQuantityTest::class, 'validQuantities')]
    public function test_operation_uses_inventory_quantity_rules(
        mixed $input,
        string $mode,
        int $scaled,
        string $expected,
    ): void {
        // Reuse Inventory's reviewed cases while requiring canonical Ownership strings.
        $this->assertSame($expected, OwnershipQuantity::normalize($input, $mode));
    }

    #[DataProviderExternal(InventoryQuantityTest::class, 'invalidQuantities')]
    public function test_operation_rejects_invalid_quantity(mixed $input, string $mode): void
    {
        // Invalid operation inputs must retain the existing domain exception contract.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipQuantity::normalize($input, $mode);
    }

    #[DataProvider('validBalances')]
    public function test_balance_keeps_exact_digits(string $input, string $expected): void
    {
        // Balances allow zero and wider totals without passing through scaled PHP integers.
        $this->assertSame($expected, OwnershipQuantity::normalizeBalance($input));
    }

    #[DataProvider('invalidBalances')]
    public function test_balance_rejects_invalid_value(string $input): void
    {
        // Derived balances reject negative, malformed, over-precision and overflowing values.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipQuantity::normalizeBalance($input);
    }

    #[DataProvider('balancePairs')]
    public function test_balance_comparison_is_exact(string $left, string $right, int $expected): void
    {
        // Adjacent thousandths must remain distinct even above the PHP scaled-integer range.
        $this->assertSame($expected, OwnershipQuantity::compare($left, $right));
    }

    #[DataProvider('invalidBalances')]
    public function test_comparison_rejects_invalid_left_balance(string $input): void
    {
        // Validate the first operand before reporting an ordering result.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipQuantity::compare($input, '0.000');
    }

    #[DataProvider('invalidBalances')]
    public function test_comparison_rejects_invalid_right_balance(string $input): void
    {
        // Validate the second operand as strictly as the first.
        $this->expectException(InvalidTenantRequest::class);
        OwnershipQuantity::compare('0.000', $input);
    }

    public static function validBalances(): array
    {
        // Cover zero, input-range boundaries and the independent DECIMAL(24,3) balance bound.
        return [
            'zero' => ['0', '0.000'],
            'zero padding' => [' +000.000 ', '0.000'],
            'one thousandth' => ['0.001', '0.001'],
            'decimal padding' => ['12.3', '12.300'],
            'leading zeros' => ['00012.345', '12.345'],
            'operation maximum' => ['99999999999.999', '99999999999.999'],
            'above operation maximum' => ['100000000000.000', '100000000000.000'],
            'above scaled integer range' => ['9223372036854775.808', '9223372036854775.808'],
            'balance maximum' => ['999999999999999999999.999', '999999999999999999999.999'],
        ];
    }

    public static function invalidBalances(): array
    {
        // Keep expected results as literal strings rather than reproducing parsing logic in tests.
        return [
            'negative' => ['-0.001'],
            'too many decimal places' => ['1.0001'],
            'extra zero precision' => ['1.0000'],
            'balance overflow' => ['1000000000000000000000.000'],
            'excess precision at maximum' => ['999999999999999999999.9999'],
            'very large value' => ['999999999999999999999999999999999999'],
            'empty' => [''],
            'blank' => ['   '],
            'scientific notation' => ['1e3'],
            'not a number' => ['NaN'],
            'infinity' => ['INF'],
            'thousands separator' => ['1,000.000'],
            'missing whole digits' => ['.001'],
            'missing fraction digits' => ['1.'],
            'text suffix' => ['1.000 items'],
        ];
    }

    public static function balancePairs(): array
    {
        // Literal expectations expose float rounding, string ordering and integer-overflow mistakes.
        return [
            'zero equality' => ['0', '0.000', 0],
            'zero shortfall' => ['0.000', '0.001', -1],
            'one thousandth available' => ['0.001', '0.000', 1],
            'normalized equality' => [' +00012.3 ', '12.300', 0],
            'different whole digit counts' => ['9.999', '10.000', -1],
            'larger than operation input' => ['100000000000.000', '99999999999.999', 1],
            'scaled integer boundary' => ['9223372036854775.808', '9223372036854775.807', 1],
            'large adjacent shortfall' => ['999999999999999999999.998', '999999999999999999999.999', -1],
            'large adjacent surplus' => ['999999999999999999999.999', '999999999999999999999.998', 1],
            'large equality' => ['999999999999999999999.999', '999999999999999999999.999', 0],
        ];
    }
}
