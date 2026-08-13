<?php

namespace App\Services\TenantModule;

use App\Repository\DailyExchangeRateSummaryRepository;
use App\Services\BaseTenantService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Services\ExchangeRate\ExchangeRateBusinessClock;

class TenantDailyExchangeRateService extends BaseTenantService
{
    public function __construct(private DailyExchangeRateSummaryRepository $repository, private TenantExchangeRatePairService $pairs, private ExchangeRateBusinessClock $clock) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }

    public function trend(string $pairCode, int $days): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->show($pairCode);
        $today = $this->clock->now($tenantId);
        $to = $today->toDateString();
        $from = $today->subDays($days - 1)->toDateString();
        $groups = $this->repository->closingTrend($tenantId, $pair->id, $from, $to);

        return [
            'pair_code' => $pair->code,
            'from_date' => $from,
            'to_date' => $to,
            'tenant_points' => $groups['tenant'] ?? [],
            'platform_points' => $groups['platform'] ?? [],
        ];
    }
}
