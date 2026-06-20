@extends('platform.layouts.app')

@section('title', __('app.platform.view.dashboard'))
@section('pageTitle', __('app.platform.view.dashboard'))
@section('pageDescription', __('app.platform.view.dashboard_description'))

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
@endsection

@php
    $money = fn ($value) => number_format((float) $value, 2);
    $count = fn ($value) => number_format((int) $value);
    $percent = fn ($value) => number_format((float) $value, 2).'%';
    $limit = fn ($value) => $value === null ? __('app.platform.view.unlimited') : $count($value);
    $riskBadgeClass = fn ($risk) => match ($risk) {
        'critical' => 'risk-critical',
        'high' => 'risk-high',
        default => 'risk-watch',
    };
@endphp

@section('content')
    <style>
        .portfolio-stack {
            display: grid;
            gap: 16px;
        }
        .dashboard-chart {
            position: relative;
            min-height: 260px;
        }
        .dashboard-chart canvas {
            width: 100% !important;
            max-height: 320px;
        }
        .metric-subtext {
            margin: 8px 0 0;
            color: var(--color-text-muted);
            font-size: 13px;
            line-height: 1.4;
        }
        .section-heading {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 14px;
        }
        .section-heading h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 20px;
        }
        .compact-list {
            display: grid;
            gap: 10px;
        }
        .compact-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--color-border);
        }
        .compact-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }
        .compact-row strong {
            color: var(--color-heading);
        }
        .usage-bar {
            width: 100%;
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--color-surface-container);
        }
        .usage-bar span {
            display: block;
            height: 100%;
            background: var(--color-primary-bright);
        }
        .badge.risk-critical {
            border-color: var(--color-danger);
            background: var(--color-danger-soft);
            color: var(--color-danger);
        }
        .badge.risk-high {
            border-color: #b45f06;
            background: #fff1d6;
            color: #7a3d00;
        }
        .badge.risk-watch {
            border-color: var(--color-border-strong);
            background: var(--color-info-soft);
            color: var(--color-info);
        }
        @media (max-width: 940px) {
            .dashboard-chart {
                min-height: 220px;
            }
        }
    </style>

    @if (! $summary['has_data'])
        <section class="panel empty-state">
            <div>
                <h2>{{ __('app.common.view.empty.no_tenant_data') }}</h2>
                <p>{{ __('app.platform.view.create_first_tenant_description') }}</p>
                <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
            </div>
        </section>
    @else
        <div class="portfolio-stack">
            <section class="grid kpi">
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.total_tenants') }}</p>
                    <p class="metric-value">{{ $count($summary['tenant_counts']['total']) }}</p>
                    <p class="metric-subtext">{{ $count($summary['tenant_counts']['active']) }} {{ __('app.platform.view.active_tenants') }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.expired_licenses') }}</p>
                    <p class="metric-value">{{ $count($summary['tenant_counts']['expired']) }}</p>
                    <p class="metric-subtext">{{ $count($summary['tenant_counts']['expiring']) }} {{ __('app.platform.view.expiring_licenses') }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.today_net') }}</p>
                    <p class="metric-value">{{ $money($summary['financial']['todayNet']) }}</p>
                    <p class="metric-subtext">{{ __('app.platform.view.today_income') }} {{ $money($summary['financial']['todayIncome']) }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.unrealized_networth') }}</p>
                    <p class="metric-value">{{ $money($summary['financial']['unrealizedNetworth']) }}</p>
                    <p class="metric-subtext">{{ __('app.platform.view.realized_networth') }} {{ $money($summary['financial']['realizedNetworth']) }}</p>
                </div>
            </section>

            <section class="grid kpi">
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.pending_payments') }}</p>
                    <p class="metric-value">{{ $count($summary['pending_payment_count']) }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.resource_usage') }}</p>
                    <p class="metric-value">{{ $summary['tenant_counts']['resourceUsagePercent'] }}%</p>
                    <p class="metric-subtext">{{ __('app.platform.view.resource_usage_description') }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.current_month_slips') }}</p>
                    <p class="metric-value">{{ $count($summary['package_usage']['currentMonthSlipCount']) }}</p>
                    <p class="metric-subtext">{{ __('app.platform.view.limit') }} {{ $limit($summary['package_usage']['maxSlipPerMonth']) }}</p>
                </div>
                <div class="panel">
                    <p class="metric-label">{{ __('app.platform.view.current_staff_count') }}</p>
                    <p class="metric-value">{{ $count($summary['package_usage']['currentStaffCount']) }}</p>
                    <p class="metric-subtext">{{ __('app.platform.view.limit') }} {{ $limit($summary['package_usage']['maxStaffCount']) }}</p>
                </div>
            </section>

            <section class="grid two">
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.plan_distribution') }}</h2>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="planDistribution"></canvas>
                    </div>
                </div>
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.license_health') }}</h2>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="licenseHealth"></canvas>
                    </div>
                </div>
            </section>

            <section class="grid two">
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.portfolio_financial_summary') }}</h2>
                    </div>
                    <div class="compact-list">
                        <div class="compact-row"><span>{{ __('app.platform.view.today_income') }}</span><strong>{{ $money($summary['financial']['todayIncome']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.today_expense') }}</span><strong>{{ $money($summary['financial']['todayExpense']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.today_net') }}</span><strong>{{ $money($summary['financial']['todayNet']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.month_to_date_income') }}</span><strong>{{ $money($summary['financial']['monthIncome']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.month_to_date_expense') }}</span><strong>{{ $money($summary['financial']['monthExpense']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.realized_networth') }}</span><strong>{{ $money($summary['financial']['realizedNetworth']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.active_collateral_minimum_retail_price') }}</span><strong>{{ $money($summary['financial']['activeCollateralMinimumRetailPrice']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.unrealized_networth') }}</span><strong>{{ $money($summary['financial']['unrealizedNetworth']) }}</strong></div>
                        <div class="compact-row"><span>{{ __('app.platform.view.previous_month_to_date_net') }}</span><strong>{{ $money($summary['financial']['previousMonthNet']) }}</strong></div>
                    </div>
                </div>
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.package_usage') }}</h2>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="packageUsage"></canvas>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="section-heading">
                    <h2>{{ __('app.platform.view.highest_risk_tenants') }}</h2>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>{{ __('app.common.view.labels.tenant') }}</th>
                            <th>{{ __('app.common.view.labels.plan') }}</th>
                            <th>{{ __('app.common.view.labels.status') }}</th>
                            <th>{{ __('app.platform.view.realized_networth') }}</th>
                            <th>{{ __('app.platform.view.unrealized_networth') }}</th>
                            <th>{{ __('app.platform.view.unpaid_debt') }}</th>
                            <th>{{ __('app.platform.view.active_principal') }}</th>
                            <th>{{ __('app.platform.view.usage') }}</th>
                            <th>{{ __('app.platform.view.risk') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($summary['risk_tenants'] as $tenant)
                            <tr>
                                <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $tenant['name'] }}</td>
                                <td data-label="{{ __('app.common.view.labels.plan') }}">{{ $tenant['plan'] }}</td>
                                <td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge">{{ $tenant['licenseStatus'] }}</span></td>
                                <td data-label="{{ __('app.platform.view.realized_networth') }}">{{ $money($tenant['monthNet']) }}</td>
                                <td data-label="{{ __('app.platform.view.unrealized_networth') }}">{{ $money($tenant['unrealizedNetworth']) }}</td>
                                <td data-label="{{ __('app.platform.view.unpaid_debt') }}">{{ $money($tenant['unpaidDebt']) }}</td>
                                <td data-label="{{ __('app.platform.view.active_principal') }}">{{ $money($tenant['activePrincipal']) }}</td>
                                <td data-label="{{ __('app.platform.view.usage') }}">
                                    {{ $count($tenant['currentMonthSlipCount']) }}/{{ $limit($tenant['maxSlipPerMonth']) }} slips<br>
                                    {{ $count($tenant['currentStaffCount']) }}/{{ $limit($tenant['maxStaffCount']) }} staff
                                </td>
                                <td data-label="{{ __('app.platform.view.risk') }}">
                                    <span class="badge {{ $riskBadgeClass($tenant['riskLabel']) }}">{{ __('app.platform.view.risk_'.$tenant['riskLabel']) }}</span>
                                    <div class="muted">{{ $tenant['riskReason'] }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">{{ __('app.common.view.empty.no_records') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid two">
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.income_leaders') }}</h2>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('app.common.view.labels.tenant') }}</th><th>{{ __('app.platform.view.month_to_date_income') }}</th><th>{{ __('app.platform.view.today_income') }}</th></tr></thead>
                            <tbody>
                            @forelse ($summary['income_leaders'] as $tenant)
                                <tr>
                                    <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $tenant['name'] }}</td>
                                    <td data-label="{{ __('app.platform.view.month_to_date_income') }}">{{ $money($tenant['monthIncome']) }}</td>
                                    <td data-label="{{ __('app.platform.view.today_income') }}">{{ $money($tenant['todayIncome']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">{{ __('app.common.view.empty.no_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.expense_leaders') }}</h2>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('app.common.view.labels.tenant') }}</th><th>{{ __('app.platform.view.month_to_date_expense') }}</th><th>{{ __('app.platform.view.today_expense') }}</th></tr></thead>
                            <tbody>
                            @forelse ($summary['expense_leaders'] as $tenant)
                                <tr>
                                    <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $tenant['name'] }}</td>
                                    <td data-label="{{ __('app.platform.view.month_to_date_expense') }}">{{ $money($tenant['monthExpense']) }}</td>
                                    <td data-label="{{ __('app.platform.view.today_expense') }}">{{ $money($tenant['todayExpense']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">{{ __('app.common.view.empty.no_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="grid two">
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.income_vs_expense') }}</h2>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="tenantIncomeExpense"></canvas>
                    </div>
                </div>
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.geographic_summary') }}</h2>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="geographicNet"></canvas>
                    </div>
                </div>
            </section>

            <section class="grid two">
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.income_streams') }}</h2>
                    </div>
                    <div class="compact-list">
                        @forelse ($summary['income_streams'] as $stream)
                            <div class="compact-row">
                                <span>{{ $stream['name'] }} <span class="muted">({{ $count($stream['transactionCount']) }})</span></span>
                                <strong>{{ $money($stream['total']) }}</strong>
                            </div>
                        @empty
                            <p class="muted">{{ __('app.common.view.empty.no_records') }}</p>
                        @endforelse
                    </div>
                </div>
                <div class="panel">
                    <div class="section-heading">
                        <h2>{{ __('app.platform.view.expense_streams') }}</h2>
                    </div>
                    <div class="compact-list">
                        @forelse ($summary['expense_streams'] as $stream)
                            <div class="compact-row">
                                <span>{{ $stream['name'] }} <span class="muted">({{ $count($stream['transactionCount']) }})</span></span>
                                <strong>{{ $money($stream['total']) }}</strong>
                            </div>
                        @empty
                            <p class="muted">{{ __('app.common.view.empty.no_records') }}</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="section-heading">
                    <h2>{{ __('app.platform.view.geographic_summary') }}</h2>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>{{ __('app.platform.view.location') }}</th>
                            <th>{{ __('app.platform.view.total_tenants') }}</th>
                            <th>{{ __('app.platform.view.active_tenants') }}</th>
                            <th>{{ __('app.platform.view.month_to_date_income') }}</th>
                            <th>{{ __('app.platform.view.month_to_date_expense') }}</th>
                            <th>{{ __('app.platform.view.realized_networth') }}</th>
                            <th>{{ __('app.platform.view.portfolio_growth') }}</th>
                            <th>{{ __('app.platform.view.best_tenant') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($summary['geographic_summary'] as $location)
                            <tr>
                                <td data-label="{{ __('app.platform.view.location') }}">{{ $location['location'] }}</td>
                                <td data-label="{{ __('app.platform.view.total_tenants') }}">{{ $count($location['tenantCount']) }}</td>
                                <td data-label="{{ __('app.platform.view.active_tenants') }}">{{ $count($location['activeTenantCount']) }}</td>
                                <td data-label="{{ __('app.platform.view.month_to_date_income') }}">{{ $money($location['monthIncome']) }}</td>
                                <td data-label="{{ __('app.platform.view.month_to_date_expense') }}">{{ $money($location['monthExpense']) }}</td>
                                <td data-label="{{ __('app.platform.view.realized_networth') }}">{{ $money($location['monthNet']) }}</td>
                                <td data-label="{{ __('app.platform.view.portfolio_growth') }}">{{ $percent($location['growthPercent']) }}</td>
                                <td data-label="{{ __('app.platform.view.best_tenant') }}">{{ $location['bestTenant'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8">{{ __('app.common.view.empty.no_records') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <script type="application/json" id="platform-dashboard-chart-data">
            @json($summary['charts'])
        </script>
    @endif
@endsection
