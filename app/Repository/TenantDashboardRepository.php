<?php

namespace App\Repository;

use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Models\TenantAccountingTransactions;
use App\Support\AccountingReferenceMapper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TenantDashboardRepository
{
    public function dashboardIncomeTotalBetween(Carbon $startDate, Carbon $endDate): float
    {
        return $this->accountingTotalBetween(
            'incoming',
            $startDate,
            $endDate,
            AccountingReferenceMapper::dashboardIncomeReferenceTypes()
        );
    }

    public function dashboardExpenseTotalBetween(Carbon $startDate, Carbon $endDate): float
    {
        return $this->accountingTotalBetween(
            'outgoing',
            $startDate,
            $endDate,
            AccountingReferenceMapper::dashboardExpenseReferenceTypes()
        );
    }

    public function dashboardNetProfitBetween(Carbon $startDate, Carbon $endDate): float
    {
        return (float) TenantAccountingTransactions::query()
            ->whereBetween('business_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where(function ($query): void {
                $query->whereNull('reference_type')
                    ->orWhereNotIn('reference_type', AccountingReferenceMapper::dashboardNetProfitExcludedReferenceTypes());
            })
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN transaction_direction = 'incoming' THEN amount
                    WHEN transaction_direction = 'outgoing' THEN -amount
                    ELSE 0
                END), 0) as net_profit
            ")
            ->value('net_profit');
    }

    public function accountingTotalBetween(string $transactionType, Carbon $startDate, Carbon $endDate, ?array $referenceTypes = null): float
    {
        return (float) TenantAccountingTransactions::query()
            ->where('transaction_direction', $transactionType)
            ->whereBetween('business_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($referenceTypes !== null, fn ($query) => $query->whereIn('reference_type', $referenceTypes))
            ->sum('amount');
    }

    public function accountingBalance(): float
    {
        return (float) TenantAccountingTransactions::query()
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN transaction_direction = 'incoming' THEN amount
                    WHEN transaction_direction = 'outgoing' THEN -amount
                    ELSE 0
                END), 0) as balance
            ")
            ->value('balance');
    }

    public function accountingDailyTotalsBetween(string $transactionType, Carbon $startDate, Carbon $endDate): Collection
    {
        return TenantAccountingTransactions::query()
            ->selectRaw('business_date as summary_date')
            ->selectRaw('SUM(amount) as total_amount')
            ->where('transaction_direction', $transactionType)
            ->whereBetween('business_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function expenseDailyTotalsBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return TenantExpense::query()
            ->selectRaw('DATE(created_at) as summary_date')
            ->selectRaw('SUM(amount) as total_amount')
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function activeLoanPrincipal(): float
    {
        return (float) PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->sum('loan_amount');
    }

    public function activeLoanCount(): int
    {
        return PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->count();
    }

    public function loanDailyTotalsBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return PawnLoanContractSlip::query()
            ->selectRaw('DATE(created_at) as summary_date')
            ->selectRaw('SUM(loan_amount) as total_amount')
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function debtDailyTotalsBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return TenantDebt::query()
            ->selectRaw('DATE(created_at) as summary_date')
            ->selectRaw('SUM(amount) as total_amount')
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function interestCollectedBetween(Carbon $startDate, Carbon $endDate): float
    {
        return (float) PawnInterestPayment::query()
            ->where('is_deleted', false)
            ->where('is_paid', true)
            ->whereBetween(DB::raw('DATE(payment_at)'), [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('payment_amount');
    }

    public function interestDailyTotalsBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return PawnInterestPayment::query()
            ->selectRaw('DATE(payment_at) as summary_date')
            ->selectRaw('SUM(payment_amount) as total_amount')
            ->where('is_deleted', false)
            ->where('is_paid', true)
            ->whereBetween(DB::raw('DATE(payment_at)'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function redemptionDailyTotalsBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return PawnRedemption::query()
            ->selectRaw('DATE(redemption_at) as summary_date')
            ->selectRaw('SUM(received_amount) as total_amount')
            ->where('is_deleted', false)
            ->whereBetween(DB::raw('DATE(redemption_at)'), [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy('summary_date');
    }

    public function activeSlipsDueOn(Carbon $date): int
    {
        return PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', $date->toDateString())
            ->count();
    }

    public function activeSlipsDueBetween(Carbon $startDate, Carbon $endDate): int
    {
        return PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereBetween(DB::raw('DATE(expire_at)'), [$startDate->toDateString(), $endDate->toDateString()])
            ->count();
    }

    public function activeOverdueLoanCount(Carbon $today): int
    {
        return PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<', $today->toDateString())
            ->count();
    }

    public function activeOverdueLoanAmount(Carbon $today): float
    {
        return (float) PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<', $today->toDateString())
            ->sum('loan_amount');
    }

    public function highRiskCustomerCount(int $trustScoreThreshold, Carbon $today): int
    {
        $overdueCustomerIds = PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<', $today->toDateString())
            ->whereNotNull('customer_id')
            ->pluck('customer_id')
            ->all();

        return TenantCustomer::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($trustScoreThreshold, $overdueCustomerIds) {
                $query->whereNull('trust_score')
                    ->orWhere('trust_score', '<', $trustScoreThreshold)
                    ->when($overdueCustomerIds !== [], fn ($customerQuery) => $customerQuery->orWhereIn('id', $overdueCustomerIds));
            })
            ->count();
    }

    public function badRepaymentHistoryCustomerCount(Carbon $today): int
    {
        return PawnLoanContractSlip::query()
            ->select('customer_id')
            ->where('is_deleted', false)
            ->whereNotNull('customer_id')
            ->where(function ($query) use ($today) {
                $query->whereRaw('LOWER(status) = ?', ['expired'])
                    ->orWhere(function ($activeQuery) use ($today) {
                        $activeQuery->whereRaw('LOWER(status) = ?', ['active'])
                            ->whereDate('expire_at', '<', $today->toDateString());
                    });
            })
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();
    }

    public function loansRequiringAttention(Carbon $today, Carbon $weekEnd, int $limit = 8): Collection
    {
        return PawnLoanContractSlip::query()
            ->with('customer')
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<=', $weekEnd->toDateString())
            ->orderByRaw('CASE WHEN DATE(expire_at) < ? THEN 0 ELSE 1 END', [$today->toDateString()])
            ->orderBy('expire_at')
            ->orderByDesc('loan_amount')
            ->limit($limit)
            ->get();
    }

    public function expenseTotalBetween(Carbon $startDate, Carbon $endDate): float
    {
        return (float) TenantExpense::query()
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');
    }

    public function collateralItemsForDashboard(): Collection
    {
        return PawnCollateralItem::query()
            ->with(['loanContract.customer', 'materialType', 'itemCategoryType'])
            ->where('is_deleted', false)
            ->whereRaw('LOWER(item_status) != ?', ['redeemed'])
            ->orderByRaw("CASE WHEN LOWER(item_status) = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('minimum_retail_price')
            ->get();
    }

    public function collateralItemsNeedingReview(Carbon $today, int $limit = 8): Collection
    {
        return PawnCollateralItem::query()
            ->with(['loanContract.customer', 'materialType', 'itemCategoryType'])
            ->where('is_deleted', false)
            ->where(function ($query) use ($today) {
                $query->whereRaw('LOWER(item_status) IN (?, ?)', ['expired', 'confiscated'])
                    ->orWhereHas('loanContract', function ($loanQuery) use ($today) {
                        $loanQuery->where('is_deleted', false)
                            ->where(function ($statusQuery) use ($today) {
                                $statusQuery->whereRaw('LOWER(status) = ?', ['expired'])
                                    ->orWhere(function ($activeQuery) use ($today) {
                                        $activeQuery->whereRaw('LOWER(status) = ?', ['active'])
                                            ->whereDate('expire_at', '<', $today->toDateString());
                                    });
                            });
                    });
            })
            ->orderByDesc('minimum_retail_price')
            ->limit($limit)
            ->get();
    }
}
