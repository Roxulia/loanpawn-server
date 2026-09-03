<?php

namespace App\Services\Interest;

use App\Exceptions\InvalidTenantRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class FixedInterestCalculatorService
{
    public function calculate(float $principal, float $rate): float
    {
        if ($principal < 0 || $rate < 0) {
            throw new InvalidTenantRequest('Principal and interest rate must be positive values.');
        }

        return round(($principal * $rate) / 100, 2);
    }

    public function nextPeriodStart(CarbonInterface $currentStart, string $periodType, int $periodCount = 1): CarbonImmutable
    {
        $date = CarbonImmutable::parse($currentStart)->startOfDay();

        return match ($this->normalizePeriodType($periodType)) {
            'Day' => $date->addDays($periodCount),
            'Week' => $date->addWeeks($periodCount),
            'Month' => $date->addMonthsNoOverflow($periodCount),
            'Year' => $date->addYearsNoOverflow($periodCount),
        };
    }

    public function normalizePeriodType(string $periodType): string
    {
        $normalized = ucfirst(strtolower(trim($periodType)));

        if (! in_array($normalized, ['Day', 'Week', 'Month', 'Year'], true)) {
            throw new InvalidTenantRequest('Period type must be Day, Week, Month, or Year.');
        }

        return $normalized;
    }
}
