<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Carbon\Carbon;

class DashboardTimeFilter extends BaseDataObject
{
    public const THIS_DAY = 'this_day';
    public const THIS_WEEK = 'this_week';
    public const THIS_MONTH = 'this_month';
    public const CUSTOM = 'custom';

    public string $timeFilter;
    public Carbon $startDate;
    public Carbon $endDate;

    public function __construct(
        string $timeFilter,
        Carbon $startDate,
        Carbon $endDate,
    ) {
        $this->timeFilter = $timeFilter;
        $this->startDate = $startDate->copy()->startOfDay();
        $this->endDate = $endDate->copy()->endOfDay();
    }

    public static function fromValidated(array $data): self
    {
        $timeFilter = $data['time_filter'] ?? self::THIS_MONTH;
        $today = Carbon::today();

        return match ($timeFilter) {
            self::THIS_DAY => new self($timeFilter, $today, $today),
            self::THIS_WEEK => new self($timeFilter, $today->copy()->startOfWeek(), $today),
            self::THIS_MONTH => new self($timeFilter, $today->copy()->startOfMonth(), $today),
            self::CUSTOM => new self(
                $timeFilter,
                Carbon::parse($data['start_at']),
                Carbon::parse($data['end_at']),
            ),
            default => new self(self::THIS_MONTH, $today->copy()->startOfMonth(), $today),
        };
    }

    public function previousPeriodStartDate(): Carbon
    {
        $days = $this->startDate->diffInDays($this->endDate) + 1;

        return $this->startDate->copy()->subDays($days)->startOfDay();
    }

    public function previousPeriodEndDate(): Carbon
    {
        return $this->startDate->copy()->subDay()->endOfDay();
    }

    public function toArray(): array
    {
        return [
            'time_filter' => $this->timeFilter,
            'start_at' => $this->startDate->toISOString(),
            'end_at' => $this->endDate->toISOString(),
        ];
    }
}
