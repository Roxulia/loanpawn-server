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

    public function resolveInterestPeriodType(?string $code, ?string $name, ?int $durationInDays): string
    {
        $unit = strtolower(trim((string) ($code ?: $name)));

        return match ($unit) {
            'daily', 'day' => 'Day',
            'weekly', 'week' => 'Week',
            'monthly', 'month' => 'Month',
            'yearly', 'year' => 'Year',
            default => $durationInDays === 7 ? 'Week' : 'Day',
        };
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, next: CarbonImmutable} */
    public function periodBounds(CarbonInterface|string $start, string $periodType, string $timezone, int $count = 1): array
    {
        $localStart = CarbonImmutable::parse($start)->setTimezone($timezone)->startOfDay();
        $next = $this->nextPeriodStart($localStart, $periodType, $count)->setTimezone($timezone)->startOfDay();

        return [
            'start' => $localStart,
            'end' => $next->subSecond(),
            'next' => $next,
        ];
    }

    public function remainingInterest(float $calculated, float $paid = 0, float $compounded = 0): float
    {
        return round(max($calculated - $paid - $compounded, 0), 2);
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
