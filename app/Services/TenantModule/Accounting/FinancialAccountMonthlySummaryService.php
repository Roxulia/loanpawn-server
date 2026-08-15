<?php

namespace App\Services\TenantModule\Accounting;

use App\Repository\Accounting\FinancialAccountMonthlySummaryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialAccountMonthlySummaryService
{
    public function __construct(private FinancialAccountMonthlySummaryRepository $repository) {}

    public function summarize(int $tenantId, CarbonImmutable $month): void
    {
        $monthStart = $month->startOfMonth()->toDateString();
        $monthEnd = $month->endOfMonth()->toDateString();

        DB::transaction(function () use ($tenantId, $monthStart, $monthEnd): void {
            $this->repository->replaceMonth(
                $tenantId,
                $monthStart,
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
