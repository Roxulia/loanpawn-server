<?php

namespace App\Services\PlatformModule;

use App\Repository\ManualPaymentRequestRepository;
use App\Repository\PlatformPortfolioDashboardRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PlatformDashboardService
{
    public function __construct(
        private AuthService $authService,
        private PlatformPortfolioDashboardRepository $portfolioRepository,
        private ManualPaymentRequestRepository $paymentRequestRepository,
    ) {
    }

    public function getSummary(): array
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $today = Carbon::today();
        $tenantCount = $this->portfolioRepository->tenantCount($platformUser->id);
        $configuredTenantCount = $this->portfolioRepository->configuredTenantCount($platformUser->id);
        $tenantRows = $this->portfolioRepository->tenantPortfolioRows($platformUser->id, $today);
        $financial = $this->financialSummary($tenantRows);
        $packageUsage = $this->packageUsage($tenantRows);
        $riskTenants = $this->riskTenants($tenantRows);
        $incomeLeaders = $this->leaderRows($tenantRows, 'monthIncome');
        $expenseLeaders = $this->leaderRows($tenantRows, 'monthExpense');
        $geographicSummary = $this->geographicSummary($tenantRows);

        return [
            'has_data' => $tenantCount > 0,
            'tenant_counts' => [
                'total' => $tenantCount,
                'active' => $this->portfolioRepository->activeTenantCount($platformUser->id),
                'expired' => $this->portfolioRepository->licenseHealth($platformUser->id)['expired'] ?? 0,
                'expiring' => $this->portfolioRepository->expiringLicenseCount($platformUser->id, 30),
                'configured' => $configuredTenantCount,
                'resourceUsagePercent' => $tenantCount > 0
                    ? (int) round(($configuredTenantCount / $tenantCount) * 100)
                    : 0,
            ],
            'plan_breakdown' => $this->portfolioRepository->planBreakdown($platformUser->id),
            'license_health' => $this->portfolioRepository->licenseHealth($platformUser->id),
            'pending_payment_count' => $this->paymentRequestRepository->countPendingByPlatformUser($platformUser->id),
            'financial' => $financial,
            'package_usage' => $packageUsage,
            'risk_tenants' => $riskTenants,
            'income_leaders' => $incomeLeaders,
            'expense_leaders' => $expenseLeaders,
            'income_streams' => $this->portfolioRepository->streamLeaders(
                $platformUser->id,
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
                'incoming',
            ),
            'expense_streams' => $this->portfolioRepository->streamLeaders(
                $platformUser->id,
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
                'outgoing',
            ),
            'geographic_summary' => $geographicSummary,
            'charts' => $this->charts(
                $this->portfolioRepository->planBreakdown($platformUser->id),
                $this->portfolioRepository->licenseHealth($platformUser->id),
                $incomeLeaders,
                $expenseLeaders,
                $geographicSummary,
                $tenantRows,
            ),
        ];
    }

    protected function financialSummary(Collection $tenantRows): array
    {
        $todayIncome = (float) $tenantRows->sum('todayIncome');
        $todayExpense = (float) $tenantRows->sum('todayExpense');
        $monthIncome = (float) $tenantRows->sum('monthIncome');
        $monthExpense = (float) $tenantRows->sum('monthExpense');
        $previousMonthNet = (float) $tenantRows->sum('previousMonthNet');
        $monthNet = $monthIncome - $monthExpense;
        $activeCollateralMinimumRetailPrice = (float) $tenantRows->sum('activeCollateralMinimumRetailPrice');
        $unrealizedNetworth = $monthNet + $activeCollateralMinimumRetailPrice;
        $growthAmount = $monthNet - $previousMonthNet;

        return [
            'todayIncome' => $todayIncome,
            'todayExpense' => $todayExpense,
            'todayNet' => $todayIncome - $todayExpense,
            'todayIncomingCount' => (int) $tenantRows->sum('todayIncomingCount'),
            'todayOutgoingCount' => (int) $tenantRows->sum('todayOutgoingCount'),
            'monthIncome' => $monthIncome,
            'monthExpense' => $monthExpense,
            'monthNet' => $monthNet,
            'realizedNetworth' => $monthNet,
            'activeCollateralMinimumRetailPrice' => $activeCollateralMinimumRetailPrice,
            'unrealizedNetworth' => $unrealizedNetworth,
            'previousMonthNet' => $previousMonthNet,
            'growthAmount' => $growthAmount,
            'growthPercent' => $previousMonthNet == 0.0
                ? ($monthNet == 0.0 ? 0.0 : 100.0)
                : round(($growthAmount / abs($previousMonthNet)) * 100, 2),
        ];
    }

    protected function packageUsage(Collection $tenantRows): array
    {
        $limitedSlipRows = $tenantRows->filter(fn ($row) => $row['maxSlipPerMonth'] !== null);
        $limitedStaffRows = $tenantRows->filter(fn ($row) => $row['maxStaffCount'] !== null);

        return [
            'currentMonthSlipCount' => (int) $tenantRows->sum('currentMonthSlipCount'),
            'currentStaffCount' => (int) $tenantRows->sum('currentStaffCount'),
            'maxSlipPerMonth' => $limitedSlipRows->isEmpty() ? null : (int) $limitedSlipRows->sum('maxSlipPerMonth'),
            'maxStaffCount' => $limitedStaffRows->isEmpty() ? null : (int) $limitedStaffRows->sum('maxStaffCount'),
            'nearOrOverSlipLimitCount' => $tenantRows->filter(fn ($row) => $this->usagePercent($row['currentMonthSlipCount'], $row['maxSlipPerMonth']) >= 80)->count(),
            'nearOrOverStaffLimitCount' => $tenantRows->filter(fn ($row) => $this->usagePercent($row['currentStaffCount'], $row['maxStaffCount']) >= 80)->count(),
            'topUsage' => $tenantRows
                ->map(fn ($row) => [
                    ...$row,
                    'slipUsagePercent' => $this->usagePercent($row['currentMonthSlipCount'], $row['maxSlipPerMonth']),
                    'staffUsagePercent' => $this->usagePercent($row['currentStaffCount'], $row['maxStaffCount']),
                ])
                ->sortByDesc(fn ($row) => max($row['slipUsagePercent'], $row['staffUsagePercent']))
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    protected function riskTenants(Collection $tenantRows): array
    {
        return $tenantRows
            ->map(function ($row) {
                $score = 0;
                $reasons = [];

                if ($row['licenseStatus'] === 'expired') {
                    $score += 35;
                    $reasons[] = 'Expired license';
                }

                if ($row['monthNet'] < 0) {
                    $score += 30;
                    $reasons[] = 'Negative MTD net';
                }

                if ($row['todayNet'] < 0) {
                    $score += 12;
                    $reasons[] = 'Negative today net';
                }

                if ($row['monthExpense'] > $row['monthIncome'] && $row['monthExpense'] > 0) {
                    $score += 15;
                    $reasons[] = 'Expenses exceed income';
                }

                if ($row['unpaidDebt'] > 0) {
                    $score += 15;
                    $reasons[] = 'Unpaid debt';
                }

                if ($row['activePrincipal'] > 0 && $row['monthIncome'] <= 0) {
                    $score += 10;
                    $reasons[] = 'Principal exposure without income';
                }

                if ($this->usagePercent($row['currentMonthSlipCount'], $row['maxSlipPerMonth']) >= 100) {
                    $score += 10;
                    $reasons[] = 'Slip limit reached';
                }

                if ($this->usagePercent($row['currentStaffCount'], $row['maxStaffCount']) >= 100) {
                    $score += 10;
                    $reasons[] = 'Staff limit reached';
                }

                return [
                    ...$row,
                    'riskScore' => $score,
                    'riskLabel' => $score >= 60 ? 'critical' : ($score >= 30 ? 'high' : 'watch'),
                    'riskReason' => $reasons === [] ? 'Normal activity' : implode(', ', array_slice($reasons, 0, 2)),
                ];
            })
            ->filter(fn ($row) => $row['riskScore'] > 0)
            ->sortByDesc('riskScore')
            ->take(5)
            ->values()
            ->all();
    }

    protected function leaderRows(Collection $tenantRows, string $metric): array
    {
        return $tenantRows
            ->filter(fn ($row) => (float) $row[$metric] > 0)
            ->sortByDesc($metric)
            ->take(5)
            ->values()
            ->all();
    }

    protected function geographicSummary(Collection $tenantRows): array
    {
        return $tenantRows
            ->groupBy(fn ($row) => ($row['country'] ?: 'Unspecified').' / '.($row['city'] ?: 'Unspecified'))
            ->map(function (Collection $rows, string $location) {
                $monthIncome = (float) $rows->sum('monthIncome');
                $monthExpense = (float) $rows->sum('monthExpense');
                $monthNet = $monthIncome - $monthExpense;
                $previousNet = (float) $rows->sum('previousMonthNet');
                $growthAmount = $monthNet - $previousNet;
                $bestTenant = $rows->sortByDesc('monthNet')->first();

                return [
                    'location' => $location,
                    'tenantCount' => $rows->count(),
                    'activeTenantCount' => $rows->where('tenantStatus', 'active')->count(),
                    'monthIncome' => $monthIncome,
                    'monthExpense' => $monthExpense,
                    'monthNet' => $monthNet,
                    'growthPercent' => $previousNet == 0.0
                        ? ($monthNet == 0.0 ? 0.0 : 100.0)
                        : round(($growthAmount / abs($previousNet)) * 100, 2),
                    'bestTenant' => $bestTenant['name'] ?? '-',
                ];
            })
            ->sortByDesc('monthNet')
            ->values()
            ->all();
    }

    protected function charts(
        array $planBreakdown,
        array $licenseHealth,
        array $incomeLeaders,
        array $expenseLeaders,
        array $geographicSummary,
        Collection $tenantRows,
    ): array {
        $leaderNames = collect([...$incomeLeaders, ...$expenseLeaders])
            ->pluck('name')
            ->unique()
            ->take(8)
            ->values();

        return [
            'planDistribution' => [
                'labels' => array_map('strtoupper', array_keys($planBreakdown)),
                'values' => array_values($planBreakdown),
            ],
            'licenseHealth' => [
                'labels' => array_map(fn ($key) => str_replace('_', ' ', ucfirst($key)), array_keys($licenseHealth)),
                'values' => array_values($licenseHealth),
            ],
            'tenantIncomeExpense' => [
                'labels' => $leaderNames->all(),
                'income' => $leaderNames->map(fn ($name) => (float) $tenantRows->firstWhere('name', $name)['monthIncome'])->all(),
                'expense' => $leaderNames->map(fn ($name) => (float) $tenantRows->firstWhere('name', $name)['monthExpense'])->all(),
            ],
            'packageUsage' => [
                'labels' => ['Monthly slips', 'Staff'],
                'current' => [
                    (int) $tenantRows->sum('currentMonthSlipCount'),
                    (int) $tenantRows->sum('currentStaffCount'),
                ],
                'max' => [
                    (int) $tenantRows->filter(fn ($row) => $row['maxSlipPerMonth'] !== null)->sum('maxSlipPerMonth'),
                    (int) $tenantRows->filter(fn ($row) => $row['maxStaffCount'] !== null)->sum('maxStaffCount'),
                ],
            ],
            'geographicNet' => [
                'labels' => collect($geographicSummary)->pluck('location')->take(8)->all(),
                'values' => collect($geographicSummary)->pluck('monthNet')->take(8)->all(),
            ],
        ];
    }

    protected function usagePercent(int|float $current, ?int $max): float
    {
        if ($max === null || $max <= 0) {
            return 0.0;
        }

        return round(((float) $current / $max) * 100, 2);
    }
}
