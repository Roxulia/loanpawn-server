<?php

namespace App\Services\TenantModule\Accounting;

use App\Repository\Accounting\TenantAccountingMonthlySummaryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TenantAccountingMonthlySummaryService
{
    public function __construct(private TenantAccountingMonthlySummaryRepository $repository) {}

    public function summarize(int $tenantId, CarbonImmutable $month, ?int $reportingCurrencyId = null): void
    {
        $monthStart = $month->startOfMonth()->toDateString();
        $monthEnd = $month->endOfMonth()->toDateString();

        DB::transaction(function () use ($tenantId, $monthStart, $monthEnd, $reportingCurrencyId): void {
            $this->repository->replaceMonth(
                $tenantId,
                $monthStart,
                $reportingCurrencyId ?? $this->repository->reportingCurrencyId($tenantId),
                $this->repository->movementRows($tenantId, $monthStart, $monthEnd),
            );
        });
    }

    public function summarizeAll(CarbonImmutable $month): void
    {
        foreach ($this->repository->tenantIds() as $tenantId) {
            $this->summarize((int) $tenantId, $month);
        }
    }
}
