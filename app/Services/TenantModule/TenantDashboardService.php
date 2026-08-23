<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\DataObjects\ResponseObjects\TenantDashboardSummary;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\TenantDashboardRepository;
use App\Services\BaseTenantService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class TenantDashboardService extends BaseTenantService
{
    private const RISK_WINDOW_DAYS = 7;

    private const HIGH_RISK_TRUST_PERCENT = 40;

    private const MEDIUM_RISK_TRUST_PERCENT = 60;

    private const LOW_MARGIN_LTV = 85;

    private const TRUST_SCORE_MAX = 255;

    public function __construct(
        private TenantDashboardRepository $repository,
        private TenantUserPermissionService $permissionService,
    ) {}

    public function summary(?DashboardTimeFilter $timeFilter = null): TenantDashboardSummary
    {
        $this->authorizeDashboardRead();

        $timeFilter ??= DashboardTimeFilter::fromValidated([]);
        $today = Carbon::today();
        $weekEnd = $today->copy()->addDays(self::RISK_WINDOW_DAYS);
        $previousStartDate = $timeFilter->previousPeriodStartDate();
        $previousEndDate = $timeFilter->previousPeriodEndDate();

        $periodIncome = $this->repository->dashboardIncomeTotalBetween($timeFilter->startDate, $timeFilter->endDate);
        $periodExpense = $this->repository->dashboardExpenseTotalBetween($timeFilter->startDate, $timeFilter->endDate);
        $periodInterest = $this->repository->interestCollectedBetween($timeFilter->startDate, $timeFilter->endDate);
        $periodNetProfit = $this->repository->dashboardNetProfitBetween($timeFilter->startDate, $timeFilter->endDate);
        $previousIncome = $this->repository->dashboardIncomeTotalBetween($previousStartDate, $previousEndDate);
        $previousExpense = $this->repository->dashboardExpenseTotalBetween($previousStartDate, $previousEndDate);
        $previousInterest = $this->repository->interestCollectedBetween($previousStartDate, $previousEndDate);
        $previousNetProfit = $this->repository->dashboardNetProfitBetween($previousStartDate, $previousEndDate);
        $activeLoans = $this->repository->activeLoans();
        $overdueLoans = $this->repository->activeOverdueLoans($today);
        $collateralItems = $this->repository->collateralItemsForDashboard();

        return new TenantDashboardSummary(
            filters: [
                'time_filter' => $timeFilter->timeFilter,
                'start_at' => $timeFilter->startDate->toISOString(),
                'end_at' => $timeFilter->endDate->toISOString(),
            ],
            financial: [
                'cashAvailable' => $this->repository->accountingBalance(),
                'activeLoanAmount' => (float) $activeLoans->sum('loan_amount'),
                'activeLoanAmounts' => $this->groupLoanAmountsByCurrency($activeLoans),
                'activeLoanCount' => $this->repository->activeLoanCount(),
                'interestCollected' => $periodInterest,
                'totalIncome' => $periodIncome,
                'totalExpenses' => $periodExpense,
                'netProfit' => $periodNetProfit,
                'previousIncome' => $previousIncome,
                'previousExpenses' => $previousExpense,
                'previousInterestCollected' => $previousInterest,
                'previousNetProfit' => $previousNetProfit,
                'chart' => $this->buildFinancialChart($timeFilter),
            ],
            risk: [
                'dueToday' => $this->repository->activeSlipsDueOn($today),
                'dueThisWeek' => $this->repository->activeSlipsDueBetween($today, $weekEnd),
                'overdueLoans' => $this->repository->activeOverdueLoanCount($today),
                'overdueAmount' => (float) $overdueLoans->sum('loan_amount'),
                'overdueAmounts' => $this->groupLoanAmountsByCurrency($overdueLoans),
                'highRiskCustomers' => $this->repository->highRiskCustomerCount($this->trustScoreFromPercent(self::HIGH_RISK_TRUST_PERCENT), $today),
                'badRepaymentHistoryCount' => $this->repository->badRepaymentHistoryCustomerCount($today),
                'loansRequiringAttention' => $this->mapLoansRequiringAttention($this->repository->loansRequiringAttention($today, $weekEnd), $today),
            ],
            collateral: $this->buildCollateralSummary($collateralItems, $today),
        );
    }

    protected function authorizeDashboardRead(): void
    {
        $this->permissionService->authorizeDashboardRead();
    }

    protected function buildFinancialChart(DashboardTimeFilter $timeFilter): array
    {
        $loanRows = $this->repository->loansCreatedBetween($timeFilter->startDate, $timeFilter->endDate)
            ->groupBy(fn (PawnLoanContractSlip $slip) => $slip->created_at->toDateString());
        $debtRows = $this->repository->debtDailyTotalsBetween($timeFilter->startDate, $timeFilter->endDate);
        $expenseRows = $this->repository->expenseDailyTotalsBetween($timeFilter->startDate, $timeFilter->endDate);
        $redemptionRows = $this->repository->redemptionDailyTotalsBetween($timeFilter->startDate, $timeFilter->endDate);
        $interestRows = $this->repository->interestDailyTotalsBetween($timeFilter->startDate, $timeFilter->endDate);

        return collect(CarbonPeriod::create($timeFilter->startDate, $timeFilter->endDate))
            ->map(function (Carbon $date) use ($loanRows, $debtRows, $expenseRows, $redemptionRows, $interestRows) {
                $key = $date->toDateString();

                return [
                    'date' => $key,
                    'loanAmount' => (float) ($loanRows->get($key)?->sum('loan_amount') ?? 0),
                    'loanAmounts' => $this->groupLoanAmountsByCurrency($loanRows->get($key, collect())),
                    'debt' => $this->summaryRowAmount($debtRows, $key),
                    'returnedAmount' => $this->summaryRowAmount($redemptionRows, $key),
                    'interest' => $this->summaryRowAmount($interestRows, $key),
                    'expenses' => $this->summaryRowAmount($expenseRows, $key),
                ];
            })
            ->values()
            ->all();
    }

    protected function summaryRowAmount(Collection $rows, string $key): float
    {
        $row = $rows->get($key);

        return $row === null ? 0.0 : (float) $row->total_amount;
    }

    protected function mapLoansRequiringAttention(iterable $slips, Carbon $today): array
    {
        return collect($slips)
            ->map(function (PawnLoanContractSlip $slip) use ($today) {
                $dueDate = $slip->expire_at;
                $overdueDays = $this->overdueDays($dueDate, $today);
                $trustPercent = $this->trustPercent($slip->customer?->trust_score);

                return [
                    'customerName' => $slip->customer?->name ?? '-',
                    'loanCode' => $slip->slip_no,
                    'dueDate' => $dueDate?->toDateString(),
                    'loanAmount' => (float) $slip->loan_amount,
                    'currency' => $this->loanCurrency($slip),
                    'overdueDays' => $overdueDays,
                    'riskLevel' => $this->riskLevel($overdueDays, $trustPercent, $dueDate, $today),
                    'trustPercent' => $trustPercent,
                ];
            })
            ->values()
            ->all();
    }

    protected function buildCollateralSummary(Collection $items, Carbon $today): array
    {
        $mappedItems = $items
            ->map(fn (PawnCollateralItem $item) => $this->mapCollateralItem($item, $today))
            ->values();
        $validValueItems = $mappedItems->filter(fn (array $item) => $item['estimatedMarketValue'] > 0);
        $totalValue = (float) $mappedItems->sum('estimatedMarketValue');
        $jewelleryValue = (float) $mappedItems
            ->filter(fn (array $item) => $item['isJewellery'])
            ->sum('estimatedMarketValue');
        $totalLoanAgainstValue = (float) $validValueItems->sum('loanAmount');
        $totalCollateralForLtv = (float) $validValueItems->sum('estimatedMarketValue');
        $reviewItems = $mappedItems
            ->filter(fn (array $item) => in_array($item['status'], ['Low Margin', 'Expired'], true))
            ->sortByDesc(fn (array $item) => $item['ltvRatio'])
            ->take(8)
            ->values();

        return [
            'totalCollateralValue' => $totalValue,
            'totalCollateralValues' => $this->groupMappedAmountsByCurrency($mappedItems, 'estimatedMarketValue'),
            'averageLtvRatio' => $totalCollateralForLtv <= 0 ? 0.0 : ($totalLoanAgainstValue / $totalCollateralForLtv) * 100,
            'goldJewelryValue' => $jewelleryValue,
            'goldJewelryValues' => $this->groupMappedAmountsByCurrency(
                $mappedItems->filter(fn (array $item) => $item['isJewellery']),
                'estimatedMarketValue',
            ),
            'expiredCollateralCount' => $mappedItems->where('status', 'Expired')->count(),
            'lowMarginCollateralItems' => $mappedItems->where('status', 'Low Margin')->count(),
            'categoryBreakdown' => $this->categoryBreakdown($mappedItems),
            'items' => $mappedItems->all(),
            'itemsNeedingReview' => $reviewItems->all(),
        ];
    }

    protected function mapCollateralItem(PawnCollateralItem $item, Carbon $today): array
    {
        $loanAmount = (float) ($item->loanContract?->loan_amount ?? 0);
        $estimatedValue = (float) $item->minimum_retail_price;
        $ltvRatio = $estimatedValue <= 0 ? 0.0 : ($loanAmount / $estimatedValue) * 100;
        $isExpired = $this->isCollateralExpired($item, $today);
        $status = match (true) {
            $isExpired => 'Expired',
            $ltvRatio >= self::LOW_MARGIN_LTV => 'Low Margin',
            default => 'Safe',
        };

        return [
            'code' => $item->code,
            'itemName' => $item->name ?: $item->code,
            'category' => $this->collateralCategory($item),
            'estimatedMarketValue' => $estimatedValue,
            'loanAmount' => $loanAmount,
            'currency' => $this->loanCurrency($item->loanContract),
            'ltvRatio' => $ltvRatio,
            'status' => $status,
            'isJewellery' => $this->isJewellery($item),
            'materialTypeId' => $item->material_type_id,
            'materialTypeName' => $item->materialType?->name,
            'itemCategoryTypeId' => $item->item_category_type_id,
            'itemCategoryTypeName' => $item->itemCategoryType?->name,
            'kyat' => (float) ($item->kyat ?? 0),
            'pal' => (float) ($item->pal ?? 0),
            'yway' => (float) ($item->yway ?? 0),
        ];
    }

    protected function categoryBreakdown(Collection $items): array
    {
        return $items
            ->groupBy(fn (array $item) => $item['category'].'|'.$this->currencyKey($item['currency']))
            ->map(fn (Collection $categoryItems) => [
                'category' => $categoryItems->first()['category'],
                'value' => (float) $categoryItems->sum('estimatedMarketValue'),
                'count' => $categoryItems->count(),
                'currency' => $categoryItems->first()['currency'],
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    protected function groupLoanAmountsByCurrency(Collection $loans): array
    {
        return $loans
            ->groupBy(fn (PawnLoanContractSlip $loan) => $this->currencyKey($this->loanCurrency($loan)))
            ->map(fn (Collection $currencyLoans) => [
                'amount' => (float) $currencyLoans->sum('loan_amount'),
                'currency' => $this->loanCurrency($currencyLoans->first()),
            ])
            ->values()
            ->all();
    }

    protected function groupMappedAmountsByCurrency(Collection $items, string $amountKey): array
    {
        return $items
            ->groupBy(fn (array $item) => $this->currencyKey($item['currency']))
            ->map(fn (Collection $currencyItems) => [
                'amount' => (float) $currencyItems->sum($amountKey),
                'currency' => $currencyItems->first()['currency'],
            ])
            ->values()
            ->all();
    }

    protected function loanCurrency(?PawnLoanContractSlip $loan): array
    {
        $currency = $loan?->account?->currency;

        return [
            'id' => $currency?->id,
            'code' => $currency?->code ?? '',
            'symbol' => $currency?->symbol ?? '',
        ];
    }

    protected function currencyKey(array $currency): string
    {
        return $currency['id'] === null ? 'unknown' : (string) $currency['id'];
    }

    protected function collateralCategory(PawnCollateralItem $item): string
    {
        if (! $this->isJewellery($item)) {
            return $item->itemCategoryType?->name ?: 'Other';
        }

        return $item->materialType?->name ?: 'Jewelry';
    }

    protected function isCollateralExpired(PawnCollateralItem $item, Carbon $today): bool
    {
        $itemStatus = strtolower((string) $item->item_status);
        $loanStatus = strtolower((string) $item->loanContract?->status);
        $expireDate = $item->loanContract?->expire_at;

        return $itemStatus === 'expired'
            || $loanStatus === 'expired'
            || ($loanStatus === 'active' && $expireDate !== null && $expireDate->lt($today));
    }

    protected function isJewellery(PawnCollateralItem $item): bool
    {
        return strtolower((string) $item->type) === 'jewellery';
    }

    protected function riskLevel(int $overdueDays, float $trustPercent, ?Carbon $dueDate, Carbon $today): string
    {
        if ($overdueDays >= self::RISK_WINDOW_DAYS || $trustPercent < self::HIGH_RISK_TRUST_PERCENT) {
            return 'High';
        }

        if ($overdueDays > 0 || $trustPercent < self::MEDIUM_RISK_TRUST_PERCENT || ($dueDate !== null && $dueDate->lte($today->copy()->addDays(self::RISK_WINDOW_DAYS)))) {
            return 'Medium';
        }

        return 'Low';
    }

    protected function overdueDays(?Carbon $dueDate, Carbon $today): int
    {
        if ($dueDate === null || $dueDate->gte($today)) {
            return 0;
        }

        return (int) $dueDate->diffInDays($today);
    }

    protected function trustPercent(?int $trustScore): float
    {
        return max(0, min(100, (($trustScore ?? 0) / self::TRUST_SCORE_MAX) * 100));
    }

    protected function trustScoreFromPercent(int $percent): int
    {
        return (int) floor((self::TRUST_SCORE_MAX * $percent) / 100);
    }
}
