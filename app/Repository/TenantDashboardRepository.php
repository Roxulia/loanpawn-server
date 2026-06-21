<?php

namespace App\Repository;

use App\Models\CoreModule\TenantAccounting;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnLoanContractSlip;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TenantDashboardRepository
{
    public function accountingTotalForDate(string $transactionType, Carbon $date): float
    {
        return $this->accountingTotalBetween($transactionType, $date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    public function accountingTotalBetween(string $transactionType, Carbon $startDate, Carbon $endDate): float
    {
        return (float) TenantAccounting::query()
            ->where('transaction_type', $transactionType)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    public function activeLoanPrincipal(): float
    {
        return (float) PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->sum('loan_amount');
    }

    public function outstandingDebt(): float
    {
        return (float) TenantDebt::query()
            ->where('is_deleted', false)
            ->where('is_paid', false)
            ->sum('amount');
    }

    public function collateralSummary(): array
    {
        $baseQuery = PawnCollateralItem::query()->where('is_deleted', false);

        return [
            'totalItems' => (clone $baseQuery)->count(),
            'jewelleryItems' => (clone $baseQuery)->whereRaw('LOWER(type) = ?', ['jewellery'])->count(),
            'normalItems' => (clone $baseQuery)->whereRaw('LOWER(type) != ?', ['jewellery'])->count(),
            'activeItems' => (clone $baseQuery)->whereRaw('LOWER(item_status) = ?', ['active'])->count(),
            'redeemedItems' => (clone $baseQuery)->whereRaw('LOWER(item_status) = ?', ['redeemed'])->count(),
            'confiscatedItems' => (clone $baseQuery)->whereRaw('LOWER(item_status) = ?', ['confiscated'])->count(),
            'estimatedValue' => (float) (clone $baseQuery)->sum('estimated_value'),
        ];
    }

    public function loanStatusCounts(): array
    {
        $baseQuery = PawnLoanContractSlip::query()->where('is_deleted', false);

        return [
            'activeSlips' => (clone $baseQuery)->whereRaw('LOWER(status) = ?', ['active'])->count(),
            'expiredSlips' => (clone $baseQuery)->whereRaw('LOWER(status) = ?', ['expired'])->count(),
            'redeemedSlips' => (clone $baseQuery)->whereRaw('LOWER(status) = ?', ['redeemed'])->count(),
        ];
    }

    /**
     * @return Collection<int, PawnLoanContractSlip>
     */
    public function nearlyExpiredSlips(Carbon $today, Carbon $limitDate, int $limit = 8): Collection
    {
        return PawnLoanContractSlip::query()
            ->with('customer')
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereBetween('expire_date', [$today->toDateString(), $limitDate->toDateString()])
            ->orderBy('expire_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function totalCustomers(): int
    {
        return TenantCustomer::query()
            ->where('is_deleted', false)
            ->count();
    }

    /**
     * @return Collection<int, TenantCustomer>
     */
    public function trustedCustomers(int $limit = 8): Collection
    {
        return TenantCustomer::query()
            ->where('is_deleted', false)
            ->orderByDesc('trust_score')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PawnLoanContractSlip>
     */
    public function customerLoanUsage(?Carbon $startDate = null, ?Carbon $endDate = null, int $limit = 8): Collection
    {
        return PawnLoanContractSlip::query()
            ->with('customer')
            ->select('customer_id')
            ->selectRaw('COUNT(*) as slip_count')
            ->selectRaw('SUM(loan_amount) as total_loan_amount')
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'active' THEN loan_amount ELSE 0 END) as active_loan_amount")
            ->selectRaw('MAX(created_date) as last_loan_date')
            ->where('is_deleted', false)
            ->when($startDate !== null && $endDate !== null, fn ($query) => $query->whereBetween('created_at', [$startDate, $endDate]))
            ->groupBy('customer_id')
            ->orderByDesc('active_loan_amount')
            ->orderByDesc('total_loan_amount')
            ->limit($limit)
            ->get();
    }

    public function activeLoanUsageByCustomerIds(array $customerIds): Collection
    {
        if ($customerIds === []) {
            return collect();
        }

        return PawnLoanContractSlip::query()
            ->select('customer_id')
            ->selectRaw('COUNT(*) as active_slip_count')
            ->selectRaw('SUM(loan_amount) as active_loan_amount')
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');
    }

    public function expenseTotalForDate(Carbon $date): float
    {
        return $this->expenseTotalBetween($date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    public function expenseTotalBetween(Carbon $startDate, Carbon $endDate): float
    {
        return (float) TenantExpense::query()
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    public function expenseTotalForMonth(Carbon $date): float
    {
        return $this->expenseTotalBetween($date->copy()->startOfMonth(), $date->copy()->endOfMonth());
    }

    /**
     * @return Collection<int, TenantExpense>
     */
    public function recentExpenses(int $limit = 6): Collection
    {
        return TenantExpense::query()
            ->with('expenseType')
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, TenantExpense>
     */
    public function expensesByType(Carbon $date, int $limit = 6): Collection
    {
        return $this->expensesByTypeBetween($date->copy()->startOfMonth(), $date->copy()->endOfMonth(), $limit);
    }

    /**
     * @return Collection<int, TenantExpense>
     */
    public function expensesByTypeBetween(Carbon $startDate, Carbon $endDate, int $limit = 6): Collection
    {
        return TenantExpense::query()
            ->with('expenseType')
            ->select('expense_type_id')
            ->selectRaw('SUM(amount) as total_amount')
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('expense_type_id')
            ->orderByDesc('total_amount')
            ->limit($limit)
            ->get();
    }
}
