<?php

namespace App\Console\Commands;

use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\Accounting\FinancialAccountMonthlySummaryService;
use App\Services\TenantModule\Accounting\TenantAccountingMonthlySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SummarizeMonthlyFinancialMovements extends Command
{
    protected $signature = 'finance:summarize-monthly
        {--tenant= : Limit the backfill to one tenant ID}
        {--from= : First month in YYYY-MM format}
        {--to= : Last completed month in YYYY-MM format}';

    protected $description = 'Build idempotent monthly accounting and financial account movement summaries';

    public function __construct(
        private TenantSettingRepository $tenantRepository,
        private TenantAccountingMonthlySummaryService $accountingSummaries,
        private FinancialAccountMonthlySummaryService $accountSummaries,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = CarbonImmutable::parse(($this->option('from') ?: now()->subMonth()->format('Y-m')).'-01')->startOfMonth();
        $to = CarbonImmutable::parse(($this->option('to') ?: now()->subMonth()->format('Y-m')).'-01')->startOfMonth();

        if ($to->isBefore($from) || $to->isCurrentMonth() || $to->isFuture()) {
            $this->error('The month range must be ordered and contain completed months only.');

            return self::FAILURE;
        }

        $tenantIds = $this->option('tenant')
            ? collect([(int) $this->option('tenant')])
            : $this->tenantRepository->allTenantIds();

        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            foreach ($tenantIds as $tenantId) {
                $this->accountingSummaries->summarize((int) $tenantId, $month);
                $this->accountSummaries->summarize((int) $tenantId, $month);
            }
        }

        $this->info('Monthly financial summaries were rebuilt.');

        return self::SUCCESS;
    }
}
