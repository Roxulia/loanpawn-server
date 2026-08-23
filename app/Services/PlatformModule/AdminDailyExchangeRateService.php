<?php

namespace App\Services\PlatformModule;

use App\Repository\DailyExchangeRateSummaryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminDailyExchangeRateService
{
    public function __construct(private DailyExchangeRateSummaryRepository $repository) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->platform($perPage);
    }

    public function closingTrend(int $pairId, int $days): array
    {
        $toDate = CarbonImmutable::today(config('app.timezone'));
        $fromDate = $toDate->subDays($days - 1);

        return $this->repository->platformClosingTrend(
            $pairId,
            $fromDate->toDateString(),
            $toDate->toDateString(),
        );
    }
}
