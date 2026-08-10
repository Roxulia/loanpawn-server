<?php

namespace App\Services\PlatformModule;

use App\Repository\DailyExchangeRateSummaryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminDailyExchangeRateService
{
    public function __construct(private DailyExchangeRateSummaryRepository $repository) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->platform($perPage);
    }
}
