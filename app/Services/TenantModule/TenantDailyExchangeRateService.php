<?php

namespace App\Services\TenantModule;

use App\Repository\DailyExchangeRateSummaryRepository;
use App\Services\BaseTenantService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantDailyExchangeRateService extends BaseTenantService
{
    public function __construct(private DailyExchangeRateSummaryRepository $repository) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }
}
