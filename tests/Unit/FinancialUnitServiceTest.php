<?php

namespace Tests\Unit;

use App\Enums\FinancialUnit;
use App\Services\TenantModule\FinancialUnitService;
use Tests\TestCase;

class FinancialUnitServiceTest extends TestCase
{
    public function test_it_exposes_every_supported_unit_and_multiplier(): void
    {
        $options = app(FinancialUnitService::class)->options();

        $this->assertSame(
            array_map(static fn (FinancialUnit $unit): string => $unit->value, FinancialUnit::cases()),
            array_column($options, 'code'),
        );
        $this->assertSame([1, 1_000, 100_000, 1_000_000, 10_000_000, 1_000_000_000], array_column($options, 'multiplier'));
    }

    public function test_it_normalizes_amounts_and_defaults_to_unit(): void
    {
        $service = app(FinancialUnitService::class);

        $this->assertSame(250_000.0, $service->toBase(2.5, FinancialUnit::Lakh->value));
        $this->assertSame(42.0, $service->toBase(42, null));
    }
}
