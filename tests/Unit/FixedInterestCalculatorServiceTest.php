<?php

namespace Tests\Unit;

use App\Services\Interest\FixedInterestCalculatorService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FixedInterestCalculatorServiceTest extends TestCase
{
    public function test_manual_compounding_includes_a_row_started_earlier_on_the_same_day(): void
    {
        $service = new FixedInterestCalculatorService();

        $this->assertTrue($service->isEligibleForCompounding(
            false,
            100,
            0,
            0,
            CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Yangon'),
            CarbonImmutable::parse('2026-09-18 10:30:00', 'Asia/Yangon'),
            'Asia/Yangon',
        ));
    }

    public function test_compounding_excludes_a_row_starting_at_the_exact_request_time(): void
    {
        $service = new FixedInterestCalculatorService();
        $requestAt = CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Yangon');

        $this->assertFalse($service->isEligibleForCompounding(
            false,
            100,
            0,
            0,
            $requestAt,
            $requestAt,
            'Asia/Yangon',
        ));
    }

    public function test_scheduled_midnight_cutoff_excludes_the_new_business_day(): void
    {
        $service = new FixedInterestCalculatorService();

        $this->assertFalse($service->isEligibleForCompounding(
            false,
            100,
            0,
            0,
            CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Yangon'),
            CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Yangon'),
            'Asia/Yangon',
        ));
    }
}
