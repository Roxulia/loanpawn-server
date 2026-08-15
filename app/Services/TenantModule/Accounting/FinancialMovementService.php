<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\ResponseObjects\FinancialMovementSummary;
use App\Repository\Accounting\FinancialMovementRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantUserPermissionService;
use Carbon\CarbonImmutable;

class FinancialMovementService extends BaseTenantService
{
    public function __construct(
        private FinancialMovementRepository $repository,
        private ReportingCurrencyRecalculationService $reportingCurrencyRecalculationService,
        private TenantUserPermissionService $permissionService,
    ) {}

    public function between(CarbonImmutable $startDate, CarbonImmutable $endDate): FinancialMovementSummary
    {
        $this->permissionService->authorizeAccountingList();
        $tenantId = $this->resolveCurrentTenantId();
        $effectiveCurrencyId = $this->reportingCurrencyRecalculationService->effectiveCurrencyId($tenantId);
        $accounting = [];
        $accounts = [];
        $currentMonth = CarbonImmutable::now()->startOfMonth();

        for ($month = $startDate->startOfMonth(); $month->lessThanOrEqualTo($endDate->startOfMonth()); $month = $month->addMonth()) {
            $segmentStart = $startDate->greaterThan($month) ? $startDate : $month;
            $monthEnd = $month->endOfMonth();
            $segmentEnd = $endDate->lessThan($monthEnd) ? $endDate : $monthEnd;
            $isCompletedFullMonth = $month->isBefore($currentMonth)
                && $segmentStart->isSameDay($month)
                && $segmentEnd->isSameDay($monthEnd);

            $accountingRows = $isCompletedFullMonth
                ? $this->repository->accountingSummary($tenantId, $month->toDateString())
                : $this->repository->accountingBase($tenantId, $segmentStart->toDateString(), $segmentEnd->toDateString(), $effectiveCurrencyId);
            $accountRows = $isCompletedFullMonth
                ? $this->repository->accountSummary($tenantId, $month->toDateString())
                : $this->repository->accountBase($tenantId, $segmentStart->toDateString(), $segmentEnd->toDateString());

            foreach ($accountingRows as $row) {
                $incoming = (float) $row->total_incoming;
                $outgoing = (float) $row->total_outgoing;
                $reportingIncoming = (float) $row->reporting_total_incoming;
                $reportingOutgoing = (float) $row->reporting_total_outgoing;
                $accounting[] = [
                    'month' => $month->format('Y-m'),
                    'source' => $isCompletedFullMonth ? 'summary' : 'base',
                    'currency_id' => $row->currency_id === null ? null : (int) $row->currency_id,
                    'reporting_currency_id' => (int) $row->reporting_currency_id,
                    'total_incoming' => $incoming,
                    'total_outgoing' => $outgoing,
                    'total_internal' => (float) ($row->total_internal ?? 0),
                    'net_movement' => $incoming - $outgoing,
                    'reporting_net_movement' => $reportingIncoming - $reportingOutgoing,
                    'transaction_count' => (int) $row->transaction_count,
                ];
            }

            foreach ($accountRows as $row) {
                $debit = (float) $row->total_debit;
                $credit = (float) $row->total_credit;
                $accounts[] = [
                    'month' => $month->format('Y-m'),
                    'source' => $isCompletedFullMonth ? 'summary' : 'base',
                    'financial_account_id' => (int) $row->financial_account_id,
                    'currency_id' => (int) $row->currency_id,
                    'total_debit' => $debit,
                    'total_credit' => $credit,
                    'net_movement' => $debit - $credit,
                    'transaction_count' => (int) $row->transaction_count,
                ];
            }
        }

        return new FinancialMovementSummary(
            startDate: $startDate->toDateString(),
            endDate: $endDate->toDateString(),
            effectiveReportingCurrencyId: $effectiveCurrencyId,
            accounting: $accounting,
            financialAccounts: $accounts,
        );
    }
}
