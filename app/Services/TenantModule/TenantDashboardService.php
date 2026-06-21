<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\DataObjects\ResponseObjects\TenantDashboardSummary;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\TenantDashboardRepository;
use App\Services\BaseTenantService;
use Carbon\Carbon;

class TenantDashboardService extends BaseTenantService
{
    private const NEARLY_EXPIRED_DAYS = 7;

    public function __construct(
        private TenantDashboardRepository $repository,
        private TenantUserPermissionService $permissionService,
    ) {
    }

    public function summary(?DashboardTimeFilter $timeFilter = null): TenantDashboardSummary
    {
        $this->authorizeDashboardRead();

        $timeFilter ??= DashboardTimeFilter::fromValidated([]);
        $today = Carbon::today();
        $periodIncome = $this->repository->accountingTotalBetween('incoming', $timeFilter->startDate, $timeFilter->endDate);
        $periodExpense = $this->repository->accountingTotalBetween('outgoing', $timeFilter->startDate, $timeFilter->endDate);
        $todayIncome = $this->repository->accountingTotalBetween('incoming', $today->copy()->startOfDay(), $today->copy()->endOfDay());
        $todayExpense = $this->repository->accountingTotalBetween('outgoing', $today->copy()->startOfDay(), $today->copy()->endOfDay());

        return new TenantDashboardSummary(
            filters: [
                'timeFilter' => $timeFilter->timeFilter,
                'startDate' => $timeFilter->startDate->toDateString(),
                'endDate' => $timeFilter->endDate->toDateString(),
            ],
            financial: [
                'todayIncome' => $todayIncome,
                'todayExpense' => $todayExpense,
                'netToday' => $todayIncome - $todayExpense,
                'periodIncome' => $periodIncome,
                'periodExpense' => $periodExpense,
                'periodNet' => $periodIncome - $periodExpense,
                'activeLoanPrincipal' => $this->repository->activeLoanPrincipal(),
                'outstandingDebt' => $this->repository->outstandingDebt(),
            ],
            collateral: $this->repository->collateralSummary(),
            loans: [
                ...$this->repository->loanStatusCounts(),
                'nearlyExpiredSlips' => $this->mapNearlyExpiredSlips($this->repository->nearlyExpiredSlips(
                    $today,
                    $today->copy()->addDays(self::NEARLY_EXPIRED_DAYS),
                ), $today),
            ],
            customers: [
                'totalCustomers' => $this->repository->totalCustomers(),
                'trustedCustomers' => $this->trustedCustomers(),
                'topLoanUsage' => $this->mapCustomerLoanUsage($this->repository->customerLoanUsage(
                    $timeFilter->startDate,
                    $timeFilter->endDate,
                )),
            ],
            expenses: [
                'todayTotal' => $this->repository->expenseTotalBetween($today->copy()->startOfDay(), $today->copy()->endOfDay()),
                'periodTotal' => $this->repository->expenseTotalBetween($timeFilter->startDate, $timeFilter->endDate),
                'monthTotal' => $this->repository->expenseTotalBetween($today->copy()->startOfMonth(), $today->copy()->endOfMonth()),
                'recent' => $this->mapRecentExpenses($this->repository->recentExpenses()),
                'byType' => $this->mapExpensesByType($this->repository->expensesByTypeBetween(
                    $timeFilter->startDate,
                    $timeFilter->endDate,
                )),
            ],
        );
    }

    protected function authorizeDashboardRead(): void
    {
        $this->permissionService->authorizeDashboardRead();
    }

    protected function mapNearlyExpiredSlips(iterable $slips, Carbon $today): array
    {
        return collect($slips)
            ->map(fn (PawnLoanContractSlip $slip) => [
                'slipNo' => $slip->slip_no,
                'customerName' => $slip->customer?->name ?? '-',
                'loanAmount' => (float) $slip->loan_amount,
                'expireDate' => $slip->expire_date?->toDateString(),
                'daysRemaining' => $slip->expire_date === null ? 0 : $today->diffInDays($slip->expire_date, false),
            ])
            ->values()
            ->all();
    }

    protected function mapTrustedCustomers(iterable $customers): array
    {
        $customerCollection = collect($customers);
        $usageByCustomerId = $this->repository->activeLoanUsageByCustomerIds($customerCollection->pluck('id')->all());

        return $customerCollection
            ->map(function (TenantCustomer $customer) use ($usageByCustomerId) {
                $usage = $usageByCustomerId->get($customer->id);

                return [
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'trustScore' => $customer->trust_score,
                    'activeLoanAmount' => $usage === null ? 0.0 : (float) $usage->active_loan_amount,
                    'activeSlipCount' => $usage === null ? 0 : (int) $usage->active_slip_count,
                ];
            })
            ->values()
            ->all();
    }

    protected function trustedCustomers(): array
    {
        return $this->mapTrustedCustomers($this->repository->trustedCustomers());
    }

    protected function mapCustomerLoanUsage(iterable $rows): array
    {
        return collect($rows)
            ->map(fn (PawnLoanContractSlip $row) => [
                'code' => $row->customer?->code ?? (string) $row->customer_id,
                'name' => $row->customer?->name ?? 'Customer #' . $row->customer_id,
                'totalLoanAmount' => (float) $row->total_loan_amount,
                'activeLoanAmount' => (float) $row->active_loan_amount,
                'slipCount' => (int) $row->slip_count,
                'lastLoanDate' => $row->last_loan_date,
            ])
            ->values()
            ->all();
    }

    protected function mapRecentExpenses(iterable $expenses): array
    {
        return collect($expenses)
            ->map(fn (TenantExpense $expense) => [
                'code' => $expense->code,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expenseTypeName' => $expense->expenseType?->name,
                'createdAt' => $expense->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    protected function mapExpensesByType(iterable $expenses): array
    {
        return collect($expenses)
            ->map(fn (TenantExpense $expense) => [
                'name' => $expense->expenseType?->name ?? 'No expense type',
                'total' => (float) $expense->total_amount,
            ])
            ->values()
            ->all();
    }
}
