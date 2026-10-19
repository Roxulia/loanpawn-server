<?php

namespace Tests\Unit;

use App\Exceptions\InvalidTenantRequest;
use App\Support\InventoryQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryQuantityTest extends TestCase
{
    #[DataProvider('validQuantities')]
    public function test_exact_quantities_round_trip(mixed $input, string $mode, int $scaled, string $decimal): void
    {
        // Verify published decimal behavior without a database or schema setup.
        $this->assertSame($scaled, InventoryQuantity::parse($input, $mode));
        $this->assertSame($decimal, InventoryQuantity::format($scaled));
        $this->assertSame($scaled, InventoryQuantity::parseBalance($decimal));
    }

    public static function validQuantities(): array
    {
        return [
            'smallest fraction' => ['0.001', 'QUANTITY', 1, '0.001'],
            'exact decimal' => ['12.345', 'QUANTITY', 12345, '12.345'],
            'float input' => [0.001, 'QUANTITY', 1, '0.001'],
            'unique piece' => ['1.000', 'UNIQUE', 1000, '1.000'],
            'serialized pieces' => [2, 'SERIALIZED', 2000, '2.000'],
            'normalized input' => [' +0002.5 ', 'QUANTITY', 2500, '2.500'],
            'maximum storage quantity' => ['99999999999.999', 'QUANTITY', 99999999999999, '99999999999.999'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_mutation_quantities_are_rejected(mixed $input, string $mode): void
    {
        // Include values that permissive numeric coercion or rounding could wrongly accept.
        $this->expectException(InvalidTenantRequest::class);
        InventoryQuantity::parse($input, $mode);
    }

    public static function invalidQuantities(): array
    {
        return [
            ['0', 'QUANTITY'], ['-0.001', 'QUANTITY'], ['1.0001', 'QUANTITY'],
            ['100000000000.000', 'QUANTITY'], ['99999999999999999999999', 'QUANTITY'],
            ['0.999', 'UNIQUE'], ['2', 'UNIQUE'], ['1.001', 'SERIALIZED'],
            ['1e3', 'QUANTITY'], ['', 'QUANTITY'], [true, 'QUANTITY'], [null, 'QUANTITY'],
            [[], 'QUANTITY'], [INF, 'QUANTITY'], [-INF, 'QUANTITY'], [NAN, 'QUANTITY'],
        ];
    }

    public function test_exhaustion_and_one_thousandth_shortfall_remain_distinct(): void
    {
        // Zero is a valid balance, while one thousandth must never disappear in a tolerance.
        $this->assertSame(0, InventoryQuantity::parseBalance('0.000'));
        $this->assertSame('0.000', InventoryQuantity::format(0));
        $stock = InventoryQuantity::parse('1.000');
        $this->assertSame(0, InventoryQuantity::compare($stock, InventoryQuantity::parse('1')));
        $this->assertSame(-1, InventoryQuantity::compare($stock, InventoryQuantity::parse('1.001')));
        $this->assertSame(1, InventoryQuantity::compare(1, 0));
    }

    #[DataProvider('invalidScaledQuantities')]
    public function test_internal_formatting_rejects_invalid_balances(int $scaled): void
    {
        $this->expectException(InvalidTenantRequest::class);
        InventoryQuantity::format($scaled);
    }

    public static function invalidScaledQuantities(): array
    {
        return [[-1], [InventoryQuantity::MAX_THOUSANDTHS + 1]];
    }
}
