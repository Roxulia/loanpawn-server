<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
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

    public function getSummary(?DashboardTimeFilter $timeFilter = null): array
    {
        $timeFilter ??= DashboardTimeFilter::fromValidated([]);
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $today = Carbon::today();
        $tenantCount = $this->portfolioRepository->tenantCount($platformUser->id);
        $configuredTenantCount = $this->portfolioRepository->configuredTenantCount($platformUser->id);
        $tenantRows = $this->portfolioRepository->tenantPortfolioRows($platformUser->id, $today, $timeFilter);
        $financial = $this->financialSummary($tenantRows);
        $packageUsage = $this->packageUsage($tenantRows);
        $riskTenants = $this->riskTenants($tenantRows);
        $incomeLeaders = $this->leaderRows($tenantRows, 'monthIncome');
        $expenseLeaders = $this->leaderRows($tenantRows, 'monthExpense');
        $geographicSummary = $this->geographicSummary($tenantRows);
        $tenantCounts = [
            'total' => $tenantCount,
            'active' => $this->portfolioRepository->activeTenantCount($platformUser->id),
            'expired' => $this->portfolioRepository->licenseHealth($platformUser->id)['expired'] ?? 0,
            'expiring' => $this->portfolioRepository->expiringLicenseCount($platformUser->id, 30),
            'configured' => $configuredTenantCount,
            'resourceUsagePercent' => $packageUsage['resourceUsagePercent'],
            'slipUsagePercent' => $packageUsage['slipUsagePercent'],
            'staffUsagePercent' => $packageUsage['staffUsagePercent'],
            'slipCurrentCount' => $packageUsage['currentMonthSlipCount'],
            'staffCurrentCount' => $packageUsage['currentStaffCount'],
            'slipMaxCount' => $packageUsage['maxSlipPerMonth'],
            'staffMaxCount' => $packageUsage['maxStaffCount'],
        ];
        $financialPerformance = $this->financialPerformance($tenantRows, $financial, $packageUsage, $tenantCounts);
        $executiveOverview = $this->executiveOverview($tenantRows, $financial, $packageUsage, $tenantCounts, $riskTenants);

        return [
            'has_data' => $tenantCount > 0,
            'filters' => [
                'time_filter' => $timeFilter->timeFilter,
                'start_at' => $timeFilter->startDate->toISOString(),
                'end_at' => $timeFilter->endDate->toISOString(),
            ],
            'tenant_counts' => $tenantCounts,
            'plan_breakdown' => $this->portfolioRepository->planBreakdown($platformUser->id),
            'license_health' => $this->portfolioRepository->licenseHealth($platformUser->id),
            'pending_payment_count' => $this->paymentRequestRepository->countPendingByPlatformUser($platformUser->id),
            'executive_overview' => $executiveOverview,
            'financial' => $financial,
            'financial_performance' => $financialPerformance,
            'package_usage' => $packageUsage,
            'risk_tenants' => $riskTenants,
            'expiring_contract_risks' => $this->portfolioRepository->expiringContractRiskRows($platformUser->id, $today),
            'income_leaders' => $incomeLeaders,
            'expense_leaders' => $expenseLeaders,
            'income_streams' => $this->portfolioRepository->streamLeaders(
                $platformUser->id,
                $timeFilter->startDate,
                $timeFilter->endDate,
                'incoming',
            ),
            'expense_streams' => $this->portfolioRepository->streamLeaders(
                $platformUser->id,
                $timeFilter->startDate,
                $timeFilter->endDate,
                'outgoing',
            ),
            'geographic_summary' => $geographicSummary,
            'charts' => $this->charts(
                $this->portfolioRepository->planBreakdown($platformUser->id),
                $this->portfolioRepository->licenseHealth($platformUser->id),
                $geographicSummary,
                $tenantRows,
                $financialPerformance['tenantRows'],
                $executiveOverview['benchmarkRows'],
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
            'periodIncome' => $monthIncome,
            'periodExpense' => $monthExpense,
            'periodNet' => $monthNet,
            'realizedNetworth' => $monthNet,
            'activeCollateralMinimumRetailPrice' => $activeCollateralMinimumRetailPrice,
            'unrealizedNetworth' => $unrealizedNetworth,
            'previousMonthNet' => $previousMonthNet,
            'growthAmount' => $growthAmount,
            'growthPercent' => $previousMonthNet == 0.0
                ? ($monthNet == 0.0 ? 0.0 : 100.0)
                : $this->growthPercent($monthNet, $previousMonthNet),
        ];
    }

    protected function financialPerformance(Collection $tenantRows, array $financial, array $packageUsage, array $tenantCounts): array
    {
        $periodActivity = (float) $financial['periodIncome'] + (float) $financial['periodExpense'];
        $netMargin = (float) $financial['periodIncome'] > 0
            ? ((float) $financial['periodNet'] / (float) $financial['periodIncome']) * 100
            : 0.0;

        return [
            'kpis' => [
                [
                    'labelKey' => 'total_portfolio_revenue',
                    'value' => (float) $financial['periodIncome'],
                    'trend' => (float) $financial['growthPercent'],
                    'progressPercent' => $this->sharePercent((float) $financial['periodIncome'], $periodActivity),
                    'subtextKey' => 'period_revenue_share',
                    'tone' => 'primary',
                ],
                [
                    'labelKey' => 'operating_expenses',
                    'value' => (float) $financial['periodExpense'],
                    'trend' => $this->sharePercent((float) $financial['periodExpense'], $periodActivity) * -1,
                    'progressPercent' => $this->sharePercent((float) $financial['periodExpense'], $periodActivity),
                    'subtextKey' => 'period_expense_share',
                    'tone' => 'warning',
                ],
                [
                    'labelKey' => 'net_operating_income',
                    'value' => (float) $financial['periodNet'],
                    'trend' => (float) $financial['growthPercent'],
                    'progressPercent' => $this->clampedPercent($netMargin),
                    'subtextKey' => 'net_margin',
                    'tone' => 'slate',
                ],
            ],
            'tenantRows' => $this->financialTenantRows($tenantRows),
            'usageItems' => $this->financialUsageItems($packageUsage, $tenantCounts),
            'insights' => $this->financialInsights($tenantRows, $financial, $tenantCounts),
        ];
    }

    protected function executiveOverview(Collection $tenantRows, array $financial, array $packageUsage, array $tenantCounts, array $riskTenants): array
    {
        $benchmarkRows = $tenantRows
            ->sortByDesc('monthNet')
            ->take(6)
            ->map(fn ($row) => [
                'name' => $row['name'],
                'current' => (float) $row['monthNet'],
                'target' => max((float) $row['previousMonthNet'], 0.0),
            ])
            ->values()
            ->all();

        return [
            'kpis' => [
                [
                    'labelKey' => 'total_tenants',
                    'value' => (int) $tenantCounts['total'],
                    'displayType' => 'count',
                    'trend' => (float) $tenantCounts['resourceUsagePercent'],
                    'subtextKey' => 'active_tenants_count',
                    'subtextParams' => ['count' => $tenantCounts['active']],
                    'tone' => 'primary',
                    'bars' => $this->sparkBars((float) $tenantCounts['resourceUsagePercent']),
                ],
                [
                    'labelKey' => 'portfolio_net_worth',
                    'value' => (float) $financial['unrealizedNetworth'],
                    'displayType' => 'money',
                    'trend' => (float) $financial['growthPercent'],
                    'subtextKey' => 'realized_networth_amount',
                    'subtextParams' => ['amount' => number_format((float) $financial['realizedNetworth'], 2)],
                    'tone' => 'cyan',
                    'bars' => $this->sparkBars((float) $financial['growthPercent']),
                ],
                [
                    'labelKey' => 'resource_usage',
                    'value' => (float) $tenantCounts['resourceUsagePercent'],
                    'displayType' => 'percent',
                    'trend' => (float) $tenantCounts['resourceUsagePercent'],
                    'subtextKey' => 'resource_usage_breakdown',
                    'subtextParams' => [
                        'slips' => number_format((float) $tenantCounts['slipUsagePercent'], 2).'%',
                        'staff' => number_format((float) $tenantCounts['staffUsagePercent'], 2).'%',
                    ],
                    'tone' => 'warning',
                    'bars' => $this->sparkBars((float) $tenantCounts['resourceUsagePercent']),
                ],
            ],
            'benchmarkRows' => $benchmarkRows,
            'priorityEvents' => collect($riskTenants)
                ->take(4)
                ->map(fn ($row) => [
                    'tenant' => $row['name'],
                    'statusKey' => 'risk_'.$row['riskLabel'],
                    'statusTone' => match ($row['riskLabel']) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        default => 'neutral',
                    },
                    'impact' => (float) $row['monthNet'],
                    'detail' => $row['riskReason'],
                ])
                ->values()
                ->all(),
        ];
    }

    protected function financialTenantRows(Collection $tenantRows): array
    {
        return $tenantRows
            ->sortByDesc('monthNet')
            ->take(6)
            ->map(function ($row) {
                $status = $this->financialStatus($row);

                return [
                    'name' => $row['name'],
                    'location' => ($row['city'] || $row['country'])
                        ? trim(($row['city'] ?: 'Unspecified').', '.($row['country'] ?: 'Unspecified'), ', ')
                        : 'Unspecified',
                    'revenue' => (float) $row['monthIncome'],
                    'expense' => (float) $row['monthExpense'],
                    'noi' => (float) $row['monthNet'],
                    'marginPercent' => (float) $row['monthIncome'] > 0
                        ? round(((float) $row['monthNet'] / (float) $row['monthIncome']) * 100, 2)
                        : 0.0,
                    'statusKey' => $status['key'],
                    'statusTone' => $status['tone'],
                ];
            })
            ->values()
            ->all();
    }

    protected function financialStatus(array $row): array
    {
        $net = (float) $row['monthNet'];
        $marginPercent = (float) $row['monthIncome'] > 0
            ? ($net / (float) $row['monthIncome']) * 100
            : 0.0;

        if ($net < 0) {
            return ['key' => 'at_risk', 'tone' => 'danger'];
        }

        if ($net > 0 && $marginPercent >= 50.0) {
            return ['key' => 'high_yield', 'tone' => 'success'];
        }

        return ['key' => 'stable', 'tone' => 'neutral'];
    }

    protected function financialUsageItems(array $packageUsage, array $tenantCounts): array
    {
        return [
            [
                'labelKey' => 'current_month_slips',
                'current' => (int) $packageUsage['currentMonthSlipCount'],
                'limit' => $packageUsage['maxSlipPerMonth'],
                'percent' => $this->usagePercent($packageUsage['currentMonthSlipCount'], $packageUsage['maxSlipPerMonth']),
                'tone' => 'primary',
            ],
            [
                'labelKey' => 'current_staff_count',
                'current' => (int) $packageUsage['currentStaffCount'],
                'limit' => $packageUsage['maxStaffCount'],
                'percent' => $this->usagePercent($packageUsage['currentStaffCount'], $packageUsage['maxStaffCount']),
                'tone' => 'cyan',
            ],
            [
                'labelKey' => 'configured_tenants',
                'current' => (int) $tenantCounts['configured'],
                'limit' => (int) $tenantCounts['total'],
                'percent' => $this->usagePercent($tenantCounts['configured'], $tenantCounts['total']),
                'tone' => 'slate',
            ],
        ];
    }

    protected function financialInsights(Collection $tenantRows, array $financial, array $tenantCounts): array
    {
        $bestTenant = $tenantRows->sortByDesc('monthNet')->first();
        $riskCount = $tenantRows->filter(fn ($row) => (float) $row['monthNet'] < 0)->count();

        return [
            [
                'icon' => 'lightbulb',
                'titleKey' => 'financial_insight',
                'bodyKey' => 'financial_insight_body',
                'bodyParams' => [
                    'tenant' => $bestTenant['name'] ?? '-',
                    'amount' => number_format((float) ($bestTenant['monthNet'] ?? 0), 2),
                ],
                'tone' => 'primary',
            ],
            [
                'icon' => 'task_alt',
                'titleKey' => 'billing_attention',
                'bodyKey' => 'billing_attention_body',
                'bodyParams' => [
                    'count' => $riskCount,
                    'expired' => $tenantCounts['expired'],
                ],
                'tone' => 'cyan',
            ],
            [
                'icon' => 'auto_awesome',
                'titleKey' => 'optimization_suggestion',
                'bodyKey' => 'optimization_suggestion_body',
                'bodyParams' => [
                    'amount' => number_format(abs((float) $financial['growthAmount']), 2),
                ],
                'tone' => 'solid',
            ],
        ];
    }

    protected function packageUsage(Collection $tenantRows): array
    {
        $limitedSlipRows = $tenantRows->filter(fn ($row) => $row['maxSlipPerMonth'] !== null);
        $limitedStaffRows = $tenantRows->filter(fn ($row) => $row['maxStaffCount'] !== null);
        $limitedCurrentCount = (int) $limitedSlipRows->sum('currentMonthSlipCount') + (int) $limitedStaffRows->sum('currentStaffCount');
        $limitedMaxCount = $limitedSlipRows->isEmpty() && $limitedStaffRows->isEmpty()
            ? null
            : (int) $limitedSlipRows->sum('maxSlipPerMonth') + (int) $limitedStaffRows->sum('maxStaffCount');
        $maxSlipPerMonth = $limitedSlipRows->isEmpty() ? null : (int) $limitedSlipRows->sum('maxSlipPerMonth');
        $maxStaffCount = $limitedStaffRows->isEmpty() ? null : (int) $limitedStaffRows->sum('maxStaffCount');
        $slipUsagePercent = $this->usagePercent((int) $tenantRows->sum('currentMonthSlipCount'), $maxSlipPerMonth);
        $staffUsagePercent = $this->usagePercent((int) $tenantRows->sum('currentStaffCount'), $maxStaffCount);

        return [
            'currentMonthSlipCount' => (int) $tenantRows->sum('currentMonthSlipCount'),
            'currentStaffCount' => (int) $tenantRows->sum('currentStaffCount'),
            'maxSlipPerMonth' => $maxSlipPerMonth,
            'maxStaffCount' => $maxStaffCount,
            'limitedCurrentCount' => $limitedCurrentCount,
            'limitedMaxCount' => $limitedMaxCount,
            'slipUsagePercent' => $slipUsagePercent,
            'staffUsagePercent' => $staffUsagePercent,
            'resourceUsagePercent' => max($slipUsagePercent, $staffUsagePercent),
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
                    $reasons[] = 'Negative period net';
                }

                if ($row['todayNet'] < 0) {
                    $score += 12;
                    $reasons[] = 'Negative today net';
                }

                if ($row['monthExpense'] > $row['monthIncome'] && $row['monthExpense'] > 0) {
                    $score += 15;
                    $reasons[] = 'Period expenses exceed income';
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
                        : $this->growthPercent($monthNet, $previousNet),
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
        array $geographicSummary,
        Collection $tenantRows,
        array $financialTenantRows,
        array $overviewBenchmarkRows,
    ): array {
        $leaderNames = collect($financialTenantRows)->pluck('name')->take(8)->values();

        return [
            'overviewBenchmark' => [
                'labels' => collect($overviewBenchmarkRows)->pluck('name')->all(),
                'current' => collect($overviewBenchmarkRows)->pluck('current')->all(),
                'target' => collect($overviewBenchmarkRows)->pluck('target')->all(),
            ],
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
            'slipPackageUsage' => [
                'labels' => ['Monthly slips'],
                'current' => [
                    (int) $tenantRows->sum('currentMonthSlipCount'),
                ],
                'max' => [
                    (int) $tenantRows->filter(fn ($row) => $row['maxSlipPerMonth'] !== null)->sum('maxSlipPerMonth'),
                ],
            ],
            'staffPackageUsage' => [
                'labels' => ['Staff'],
                'current' => [
                    (int) $tenantRows->sum('currentStaffCount'),
                ],
                'max' => [
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

    protected function growthPercent(float $current, float $previous): float
    {
        return $previous == 0.0
            ? ($current == 0.0 ? 0.0 : 100.0)
            : round((($current - $previous) / abs($previous)) * 100, 2);
    }

    protected function sharePercent(float $value, float $total): float
    {
        if ($total <= 0.0) {
            return 0.0;
        }

        return $this->clampedPercent(($value / $total) * 100);
    }

    protected function clampedPercent(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    protected function sparkBars(float $value): array
    {
        $base = $this->clampedPercent($value);

        return collect([0.42, 0.58, 0.48, 0.72, 0.64, 0.86, 1.0])
            ->map(fn ($factor) => max(12.0, $this->clampedPercent($base * $factor)))
            ->all();
    }
}
