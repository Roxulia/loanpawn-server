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
    $filter = $summary['filters'] ?? [
        'timeFilter' => request('time_filter', 'this_month'),
        'startDate' => request('start_date'),
        'endDate' => request('end_date'),
    ];
    $selectedTimeFilter = $filter['timeFilter'] ?? 'this_month';
    $selectedStartDate = $filter['startDate'] ?? '';
    $selectedEndDate = $filter['endDate'] ?? '';
    $periodLabel = match ($selectedTimeFilter) {
        'this_week' => __('app.platform.view.this_week'),
        'this_month' => __('app.platform.view.this_month'),
        'custom' => __('app.platform.view.custom_time'),
        default => __('app.platform.view.this_day'),
    };
    $riskBadgeClass = fn ($risk) => match ($risk) {
        'critical' => 'risk-critical',
        'high' => 'risk-high',
        default => 'risk-watch',
    };
    $trendClass = fn ($value) => ((float) $value) < 0 ? 'is-negative' : 'is-positive';

    $riskRows = collect($summary['risk_tenants'] ?? []);
    $topUsageRows = collect($summary['package_usage']['topUsage'] ?? [])->take(5);
    $geographicRows = collect($summary['geographic_summary'] ?? []);
    $bestLocation = $geographicRows->sortByDesc('monthNet')->first();
    $highestGrowthLocation = $geographicRows->sortByDesc('growthPercent')->first();
@endphp

@section('content')
    <style>
        .portfolio-dashboard {
            --stitch-background: #f9f9ff;
            --stitch-surface: #ffffff;
            --stitch-surface-soft: #f0f3ff;
            --stitch-surface-raised: #e7eeff;
            --stitch-border: #d8e3fa;
            --stitch-border-strong: #bfc8cc;
            --stitch-text: #111c2c;
            --stitch-muted: #3f484b;
            --stitch-muted-soft: #6f797c;
            --stitch-primary: #006073;
            --stitch-primary-dark: #004755;
            --stitch-cyan: #31a8cc;
            --stitch-cyan-soft: #b8eaff;
            --stitch-slate: #4a5568;
            --stitch-warning: #e67e22;
            display: grid;
            gap: 18px;
        }
        .dashboard-filter-panel {
            border-color: #d8e3fa;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
        }
        .dashboard-filter {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) repeat(2, minmax(150px, 0.7fr)) auto;
            gap: 12px;
            align-items: end;
        }
        .dashboard-filter label {
            display: grid;
            gap: 6px;
            color: var(--color-text-muted);
            font-size: 13px;
        }
        .dashboard-tabs {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            gap: 6px;
            overflow-x: auto;
            border: 1px solid var(--stitch-border);
            border-radius: var(--radius-md);
            padding: 5px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 10px 28px rgba(0, 96, 115, 0.08);
        }
        .dashboard-tab {
            flex: 0 0 auto;
            min-height: 38px;
            border: 1px solid transparent;
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            background: transparent;
            color: var(--stitch-muted);
            font: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        .dashboard-tab:hover,
        .dashboard-tab[aria-selected="true"] {
            border-color: #8ad1e7;
            background: var(--stitch-surface-raised);
            color: var(--stitch-primary-dark);
        }
        .dashboard-tab-panel {
            display: grid;
            gap: 18px;
        }
        .dashboard-tab-panel[hidden] {
            display: none;
        }
        .stitch-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.8fr);
            gap: 18px;
            align-items: stretch;
            border: 1px solid var(--stitch-border);
            border-radius: var(--radius-lg);
            padding: clamp(18px, 2.4vw, 28px);
            background: linear-gradient(135deg, #ffffff 0%, #f9fbff 62%, #e7eeff 100%);
            box-shadow: 0 8px 24px rgba(0, 96, 115, 0.06);
        }
        .stitch-hero-copy {
            min-width: 0;
            display: grid;
            align-content: center;
        }
        .stitch-kicker,
        .metric-label,
        .card-kicker {
            margin: 0;
            color: var(--stitch-muted-soft);
            font-size: 11px;
            font-weight: 900;
            line-height: 16px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .stitch-hero h2 {
            margin: 7px 0 0;
            color: var(--stitch-text);
            font-family: var(--font-heading);
            font-size: clamp(24px, 3vw, 32px);
            font-weight: 800;
            line-height: 1.2;
        }
        .stitch-hero p {
            margin: 10px 0 0;
            max-width: 760px;
            color: var(--stitch-muted);
            line-height: 1.6;
        }
        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .hero-metric {
            min-width: 0;
            border: 1px solid var(--stitch-border);
            border-radius: var(--radius-md);
            padding: 14px;
            background: rgba(255, 255, 255, 0.76);
        }
        .hero-metric strong {
            display: block;
            margin-top: 6px;
            color: var(--stitch-primary-dark);
            font-family: var(--font-heading);
            font-size: clamp(18px, 2.2vw, 24px);
            font-weight: 900;
            line-height: 1.12;
            overflow-wrap: anywhere;
        }
        .hero-metric small {
            display: block;
            margin-top: 5px;
            color: var(--stitch-muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .dashboard-card {
            position: relative;
            overflow: hidden;
            border-color: var(--stitch-border);
            border-radius: var(--radius-md);
            background: var(--stitch-surface);
            box-shadow: 0 6px 18px rgba(0, 96, 115, 0.05);
        }
        .metric-card {
            min-height: 142px;
            display: grid;
            align-content: space-between;
            gap: 12px;
            padding: 18px;
        }
        .metric-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--card-accent, var(--stitch-primary));
        }
        .metric-card.accent-cyan {
            --card-accent: var(--stitch-cyan);
        }
        .metric-card.accent-slate {
            --card-accent: #7899a2;
        }
        .metric-card.accent-warning {
            --card-accent: var(--stitch-warning);
        }
        .metric-value {
            margin: 8px 0 0;
            color: var(--stitch-primary-dark);
            font-family: var(--font-heading);
            font-size: clamp(26px, 3.3vw, 34px);
            font-weight: 900;
            line-height: 1.05;
            overflow-wrap: anywhere;
        }
        .metric-subtext {
            margin: 0;
            color: var(--stitch-muted);
            font-size: 13px;
            line-height: 1.4;
        }
        .metric-trend {
            display: inline-flex;
            width: fit-content;
            border-radius: 999px;
            padding: 4px 8px;
            background: #eef9f3;
            color: #167a3d;
            font-size: 12px;
            font-weight: 900;
        }
        .metric-trend.is-negative {
            background: #ffdad6;
            color: #ba1a1a;
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
            color: var(--stitch-text);
            font-family: var(--font-heading);
            font-size: 20px;
            font-weight: 800;
            line-height: 1.25;
        }
        .section-heading p {
            margin: 4px 0 0;
            color: var(--stitch-muted-soft);
            font-size: 13px;
            line-height: 1.4;
        }
        .chart-card {
            padding: clamp(16px, 2vw, 22px);
        }
        .dashboard-chart {
            position: relative;
            min-height: 290px;
        }
        .dashboard-chart canvas {
            width: 100% !important;
            max-height: 350px;
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
            border-bottom: 1px solid var(--stitch-border);
        }
        .compact-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }
        .compact-row span {
            color: var(--stitch-muted);
        }
        .compact-row strong {
            color: var(--stitch-text);
            font-weight: 900;
            text-align: right;
        }
        .finance-layout {
            display: grid;
            grid-template-columns: minmax(280px, 0.72fr) minmax(0, 1.28fr);
            gap: 16px;
        }
        .data-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .summary-card {
            padding: clamp(16px, 2vw, 22px);
        }
        .usage-list {
            display: grid;
            gap: 12px;
        }
        .usage-item {
            display: grid;
            gap: 8px;
            border: 1px solid var(--stitch-border);
            border-radius: var(--radius-md);
            padding: 12px;
            background: #fbfcff;
        }
        .usage-item-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
        }
        .usage-item-head strong {
            min-width: 0;
            color: var(--stitch-text);
            overflow-wrap: anywhere;
        }
        .usage-bars {
            display: grid;
            gap: 7px;
        }
        .usage-bar-row {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr) 46px;
            gap: 8px;
            align-items: center;
            color: var(--stitch-muted);
            font-size: 12px;
            font-weight: 800;
        }
        .usage-track {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--stitch-surface-raised);
        }
        .usage-track span {
            display: block;
            width: min(var(--usage-value, 0%), 100%);
            height: 100%;
            border-radius: inherit;
            background: var(--stitch-primary);
        }
        .usage-track.staff span {
            background: var(--stitch-cyan);
        }
        .location-card-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .location-card {
            display: grid;
            gap: 12px;
            border: 1px solid var(--stitch-border);
            border-radius: var(--radius-md);
            padding: 16px;
            background: var(--stitch-surface);
            box-shadow: 0 6px 18px rgba(0, 96, 115, 0.05);
        }
        .location-card h3 {
            margin: 0;
            color: var(--stitch-text);
            font-family: var(--font-heading);
            font-size: 17px;
            line-height: 1.25;
        }
        .location-card dl {
            display: grid;
            gap: 8px;
            margin: 0;
        }
        .location-card div {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        .location-card dt {
            color: var(--stitch-muted-soft);
            font-size: 12px;
            font-weight: 800;
        }
        .location-card dd {
            margin: 0;
            color: var(--stitch-text);
            font-weight: 900;
            text-align: right;
        }
        .badge.risk-critical {
            border-color: #ba1a1a;
            background: #ffdad6;
            color: #93000a;
        }
        .badge.risk-high {
            border-color: var(--stitch-warning);
            background: #fff1d6;
            color: #8a4b00;
        }
        .badge.risk-watch {
            border-color: #8ad1e7;
            background: #e7eeff;
            color: var(--stitch-primary-dark);
        }
        .table-wrap table {
            font-size: 14px;
        }
        .table-wrap th {
            background: var(--stitch-surface-soft);
            color: var(--stitch-muted);
        }
        .table-wrap td {
            color: var(--stitch-text);
        }
        .numeric {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        @media (max-width: 1180px) {
            .kpi-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .location-card-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 1100px) {
            .stitch-hero,
            .finance-layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 940px) {
            .dashboard-filter {
                grid-template-columns: 1fr;
            }
            .dashboard-chart {
                min-height: 240px;
            }
            .dashboard-tabs {
                top: 72px;
            }
        }
        @media (max-width: 640px) {
            .hero-metrics,
            .kpi-strip,
            .data-card-grid,
            .location-card-grid {
                grid-template-columns: 1fr;
            }
            .metric-card {
                min-height: 124px;
            }
            .usage-bar-row {
                grid-template-columns: 38px minmax(0, 1fr);
            }
            .usage-bar-row strong {
                grid-column: 2;
            }
        }
    </style>

    <section class="panel dashboard-filter-panel">
        <form class="dashboard-filter" method="GET" action="{{ route('platform.dashboard') }}">
            <label>
                <span>{{ __('app.platform.view.time_filter') }}</span>
                <select name="time_filter" data-dashboard-time-filter>
                    <option value="this_day" @selected($selectedTimeFilter === 'this_day')>{{ __('app.platform.view.this_day') }}</option>
                    <option value="this_week" @selected($selectedTimeFilter === 'this_week')>{{ __('app.platform.view.this_week') }}</option>
                    <option value="this_month" @selected($selectedTimeFilter === 'this_month')>{{ __('app.platform.view.this_month') }}</option>
                    <option value="custom" @selected($selectedTimeFilter === 'custom')>{{ __('app.platform.view.custom_time') }}</option>
                </select>
            </label>
            <label data-dashboard-custom-date>
                <span>{{ __('app.platform.view.start_date') }}</span>
                <input type="date" name="start_date" value="{{ $selectedStartDate }}">
            </label>
            <label data-dashboard-custom-date>
                <span>{{ __('app.platform.view.end_date') }}</span>
                <input type="date" name="end_date" value="{{ $selectedEndDate }}">
            </label>
            <button type="submit" class="button primary">{{ __('app.platform.view.apply_filter') }}</button>
        </form>
    </section>

    @if (! $summary['has_data'])
        <section class="panel empty-state">
            <div>
                <h2>{{ __('app.common.view.empty.no_tenant_data') }}</h2>
                <p>{{ __('app.platform.view.create_first_tenant_description') }}</p>
                <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
            </div>
        </section>
    @else
        <div class="portfolio-dashboard" data-dashboard-tabs>
            <nav class="dashboard-tabs" aria-label="{{ __('app.platform.view.dashboard') }}">
                <button type="button" class="dashboard-tab" id="dashboard-tab-overview" data-dashboard-tab="overview" aria-controls="dashboard-panel-overview" aria-selected="true">{{ __('app.platform.view.executive_overview') }}</button>
                <button type="button" class="dashboard-tab" id="dashboard-tab-financial" data-dashboard-tab="financial" aria-controls="dashboard-panel-financial" aria-selected="false">{{ __('app.platform.view.financial_performance') }}</button>
                <button type="button" class="dashboard-tab" id="dashboard-tab-risk" data-dashboard-tab="risk" aria-controls="dashboard-panel-risk" aria-selected="false">{{ __('app.platform.view.tenant_risk_performance') }}</button>
                <button type="button" class="dashboard-tab" id="dashboard-tab-geographic" data-dashboard-tab="geographic" aria-controls="dashboard-panel-geographic" aria-selected="false">{{ __('app.platform.view.geographic_analysis') }}</button>
            </nav>

            <section class="dashboard-tab-panel" id="dashboard-panel-overview" data-dashboard-panel="overview" aria-labelledby="dashboard-tab-overview">
                <div class="stitch-hero">
                    <div class="stitch-hero-copy">
                        <p class="stitch-kicker">{{ $periodLabel }}</p>
                        <h2>{{ __('app.platform.view.executive_overview') }}</h2>
                        <p>{{ __('app.platform.view.dashboard_description') }}</p>
                    </div>
                    <div class="hero-metrics">
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.period_net', ['period' => $periodLabel]) }}</span>
                            <strong>{{ $money($summary['financial']['periodNet']) }}</strong>
                            <small>{{ __('app.platform.view.previous_period_net') }} {{ $money($summary['financial']['previousMonthNet']) }}</small>
                        </div>
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.resource_usage') }}</span>
                            <strong>{{ $summary['tenant_counts']['resourceUsagePercent'] }}%</strong>
                            <small>{{ $count($summary['tenant_counts']['configured']) }} configured / {{ $count($summary['tenant_counts']['total']) }} total</small>
                        </div>
                    </div>
                </div>

                <section class="kpi-strip">
                    <div class="panel dashboard-card metric-card">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.total_tenants') }}</p>
                            <p class="metric-value">{{ $count($summary['tenant_counts']['total']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ $count($summary['tenant_counts']['active']) }} {{ __('app.platform.view.active_tenants') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-warning">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.expired_licenses') }}</p>
                            <p class="metric-value">{{ $count($summary['tenant_counts']['expired']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ $count($summary['tenant_counts']['expiring']) }} {{ __('app.platform.view.expiring_licenses') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.pending_payments') }}</p>
                            <p class="metric-value">{{ $count($summary['pending_payment_count']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ __('app.common.view.labels.payment') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-slate">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.unrealized_networth') }}</p>
                            <p class="metric-value">{{ $money($summary['financial']['unrealizedNetworth']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ __('app.platform.view.realized_networth') }} {{ $money($summary['financial']['realizedNetworth']) }}</p>
                    </div>
                </section>

                <section class="kpi-strip">
                    <div class="panel dashboard-card metric-card">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.period_net', ['period' => $periodLabel]) }}</p>
                            <p class="metric-value">{{ $money($summary['financial']['periodNet']) }}</p>
                        </div>
                        <span class="metric-trend {{ $trendClass($summary['financial']['growthPercent']) }}">{{ $percent($summary['financial']['growthPercent']) }}</span>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.current_month_slips') }}</p>
                            <p class="metric-value">{{ $count($summary['package_usage']['currentMonthSlipCount']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ __('app.platform.view.limit') }} {{ $limit($summary['package_usage']['maxSlipPerMonth']) }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.current_staff_count') }}</p>
                            <p class="metric-value">{{ $count($summary['package_usage']['currentStaffCount']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ __('app.platform.view.limit') }} {{ $limit($summary['package_usage']['maxStaffCount']) }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-slate">
                        <div>
                            <p class="metric-label">{{ __('app.platform.view.active_collateral_minimum_retail_price') }}</p>
                            <p class="metric-value">{{ $money($summary['financial']['activeCollateralMinimumRetailPrice']) }}</p>
                        </div>
                        <p class="metric-subtext">{{ __('app.platform.view.portfolio_financial_summary') }}</p>
                    </div>
                </section>

                <section class="grid two">
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.plan_distribution') }}</h2>
                                <p>{{ __('app.common.view.labels.current_plan') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="planDistribution"></canvas>
                        </div>
                    </div>
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.license_health') }}</h2>
                                <p>{{ __('app.platform.view.expiring_licenses') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="licenseHealth"></canvas>
                        </div>
                    </div>
                </section>
            </section>

            <section class="dashboard-tab-panel" id="dashboard-panel-financial" data-dashboard-panel="financial" aria-labelledby="dashboard-tab-financial" hidden>
                <div class="stitch-hero">
                    <div class="stitch-hero-copy">
                        <p class="stitch-kicker">{{ __('app.platform.view.portfolio_financial_summary') }}</p>
                        <h2>{{ __('app.platform.view.financial_performance') }}</h2>
                        <p>{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }} / {{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}</p>
                    </div>
                    <div class="hero-metrics">
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.today_net') }}</span>
                            <strong>{{ $money($summary['financial']['todayNet']) }}</strong>
                            <small>{{ $count($summary['financial']['todayIncomingCount']) }} in / {{ $count($summary['financial']['todayOutgoingCount']) }} out</small>
                        </div>
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.portfolio_growth') }}</span>
                            <strong>{{ $percent($summary['financial']['growthPercent']) }}</strong>
                            <small>{{ $money($summary['financial']['growthAmount']) }}</small>
                        </div>
                    </div>
                </div>

                <section class="kpi-strip">
                    <div class="panel dashboard-card metric-card">
                        <div><p class="metric-label">{{ __('app.platform.view.today_income') }}</p><p class="metric-value">{{ $money($summary['financial']['todayIncome']) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.this_day') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-warning">
                        <div><p class="metric-label">{{ __('app.platform.view.today_expense') }}</p><p class="metric-value">{{ $money($summary['financial']['todayExpense']) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.this_day') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div><p class="metric-label">{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }}</p><p class="metric-value">{{ $money($summary['financial']['periodIncome']) }}</p></div>
                        <p class="metric-subtext">{{ $periodLabel }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-slate">
                        <div><p class="metric-label">{{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}</p><p class="metric-value">{{ $money($summary['financial']['periodExpense']) }}</p></div>
                        <p class="metric-subtext">{{ $periodLabel }}</p>
                    </div>
                </section>

                <div class="finance-layout">
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.portfolio_financial_summary') }}</h2>
                                <p>{{ __('app.platform.view.unrealized_networth') }}</p>
                            </div>
                        </div>
                        <div class="compact-list">
                            <div class="compact-row"><span>{{ __('app.platform.view.today_net') }}</span><strong>{{ $money($summary['financial']['todayNet']) }}</strong></div>
                            <div class="compact-row"><span>{{ __('app.platform.view.period_net', ['period' => $periodLabel]) }}</span><strong>{{ $money($summary['financial']['periodNet']) }}</strong></div>
                            <div class="compact-row"><span>{{ __('app.platform.view.realized_networth') }}</span><strong>{{ $money($summary['financial']['realizedNetworth']) }}</strong></div>
                            <div class="compact-row"><span>{{ __('app.platform.view.active_collateral_minimum_retail_price') }}</span><strong>{{ $money($summary['financial']['activeCollateralMinimumRetailPrice']) }}</strong></div>
                            <div class="compact-row"><span>{{ __('app.platform.view.unrealized_networth') }}</span><strong>{{ $money($summary['financial']['unrealizedNetworth']) }}</strong></div>
                            <div class="compact-row"><span>{{ __('app.platform.view.previous_period_net') }}</span><strong>{{ $money($summary['financial']['previousMonthNet']) }}</strong></div>
                        </div>
                    </div>
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.income_vs_expense') }}</h2>
                                <p>{{ __('app.platform.view.financial_performance') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="tenantIncomeExpense"></canvas>
                        </div>
                    </div>
                </div>

                <section class="grid two">
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading"><h2>{{ __('app.platform.view.income_leaders') }}</h2></div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>{{ __('app.common.view.labels.tenant') }}</th><th class="numeric">{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }}</th><th class="numeric">{{ __('app.platform.view.today_income') }}</th></tr></thead>
                                <tbody>
                                @forelse ($summary['income_leaders'] as $tenant)
                                    <tr>
                                        <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $tenant['name'] }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }}">{{ $money($tenant['monthIncome']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.today_income') }}">{{ $money($tenant['todayIncome']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3">{{ __('app.common.view.empty.no_records') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading"><h2>{{ __('app.platform.view.expense_leaders') }}</h2></div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>{{ __('app.common.view.labels.tenant') }}</th><th class="numeric">{{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}</th><th class="numeric">{{ __('app.platform.view.today_expense') }}</th></tr></thead>
                                <tbody>
                                @forelse ($summary['expense_leaders'] as $tenant)
                                    <tr>
                                        <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $tenant['name'] }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}">{{ $money($tenant['monthExpense']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.today_expense') }}">{{ $money($tenant['todayExpense']) }}</td>
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
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading"><h2>{{ __('app.platform.view.income_streams') }}</h2></div>
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
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading"><h2>{{ __('app.platform.view.expense_streams') }}</h2></div>
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
            </section>

            <section class="dashboard-tab-panel" id="dashboard-panel-risk" data-dashboard-panel="risk" aria-labelledby="dashboard-tab-risk" hidden>
                <div class="stitch-hero">
                    <div class="stitch-hero-copy">
                        <p class="stitch-kicker">{{ __('app.platform.view.highest_risk_tenants') }}</p>
                        <h2>{{ __('app.platform.view.tenant_risk_performance') }}</h2>
                        <p>{{ __('app.platform.view.risk') }} / {{ __('app.platform.view.usage') }} / {{ __('app.platform.view.license_health') }}</p>
                    </div>
                    <div class="hero-metrics">
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.unpaid_debt') }}</span>
                            <strong>{{ $money($riskRows->sum('unpaidDebt')) }}</strong>
                            <small>{{ $count($riskRows->count()) }} {{ __('app.platform.view.risk') }}</small>
                        </div>
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.active_principal') }}</span>
                            <strong>{{ $money($riskRows->sum('activePrincipal')) }}</strong>
                            <small>{{ __('app.platform.view.active_principal') }}</small>
                        </div>
                    </div>
                </div>

                <section class="kpi-strip">
                    <div class="panel dashboard-card metric-card accent-warning">
                        <div><p class="metric-label">{{ __('app.platform.view.critical_risk_tenants') }}</p><p class="metric-value">{{ $count($riskRows->where('riskLabel', 'critical')->count()) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.risk_critical') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-warning">
                        <div><p class="metric-label">{{ __('app.platform.view.high_risk_tenants') }}</p><p class="metric-value">{{ $count($riskRows->where('riskLabel', 'high')->count()) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.risk_high') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div><p class="metric-label">{{ __('app.platform.view.slip_limit_pressure') }}</p><p class="metric-value">{{ $count($summary['package_usage']['nearOrOverSlipLimitCount']) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.current_month_slips') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div><p class="metric-label">{{ __('app.platform.view.staff_limit_pressure') }}</p><p class="metric-value">{{ $count($summary['package_usage']['nearOrOverStaffLimitCount']) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.current_staff_count') }}</p>
                    </div>
                </section>

                <section class="grid two">
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.package_usage') }}</h2>
                                <p>{{ __('app.platform.view.usage') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="packageUsage"></canvas>
                        </div>
                    </div>
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.top_usage_pressure') }}</h2>
                                <p>{{ __('app.platform.view.current_month_slips') }} / {{ __('app.platform.view.current_staff_count') }}</p>
                            </div>
                        </div>
                        <div class="usage-list">
                            @forelse ($topUsageRows as $tenant)
                                <div class="usage-item">
                                    <div class="usage-item-head">
                                        <strong>{{ $tenant['name'] }}</strong>
                                        <span class="badge">{{ $tenant['plan'] }}</span>
                                    </div>
                                    <div class="usage-bars">
                                        <div class="usage-bar-row">
                                            <span>{{ __('app.platform.view.slips') }}</span>
                                            <div class="usage-track" style="--usage-value: {{ $tenant['slipUsagePercent'] }}%"><span></span></div>
                                            <strong>{{ $percent($tenant['slipUsagePercent']) }}</strong>
                                        </div>
                                        <div class="usage-bar-row">
                                            <span>{{ __('app.platform.view.staff') }}</span>
                                            <div class="usage-track staff" style="--usage-value: {{ $tenant['staffUsagePercent'] }}%"><span></span></div>
                                            <strong>{{ $percent($tenant['staffUsagePercent']) }}</strong>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="muted">{{ __('app.common.view.empty.no_records') }}</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="panel dashboard-card summary-card">
                    <div class="section-heading">
                        <div>
                            <h2>{{ __('app.platform.view.highest_risk_tenants') }}</h2>
                            <p>{{ __('app.platform.view.unpaid_debt') }} / {{ __('app.platform.view.active_principal') }}</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>{{ __('app.common.view.labels.tenant') }}</th>
                                <th>{{ __('app.common.view.labels.plan') }}</th>
                                <th>{{ __('app.common.view.labels.status') }}</th>
                                <th class="numeric">{{ __('app.platform.view.realized_networth') }}</th>
                                <th class="numeric">{{ __('app.platform.view.unrealized_networth') }}</th>
                                <th class="numeric">{{ __('app.platform.view.unpaid_debt') }}</th>
                                <th class="numeric">{{ __('app.platform.view.active_principal') }}</th>
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
                                    <td class="numeric" data-label="{{ __('app.platform.view.realized_networth') }}">{{ $money($tenant['monthNet']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.unrealized_networth') }}">{{ $money($tenant['unrealizedNetworth']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.unpaid_debt') }}">{{ $money($tenant['unpaidDebt']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.active_principal') }}">{{ $money($tenant['activePrincipal']) }}</td>
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
                                <tr><td colspan="9">{{ __('app.common.view.empty.no_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section class="dashboard-tab-panel" id="dashboard-panel-geographic" data-dashboard-panel="geographic" aria-labelledby="dashboard-tab-geographic" hidden>
                <div class="stitch-hero">
                    <div class="stitch-hero-copy">
                        <p class="stitch-kicker">{{ __('app.platform.view.geographic_summary') }}</p>
                        <h2>{{ __('app.platform.view.geographic_analysis') }}</h2>
                        <p>{{ __('app.platform.view.location') }} / {{ __('app.platform.view.portfolio_growth') }} / {{ __('app.platform.view.best_tenant') }}</p>
                    </div>
                    <div class="hero-metrics">
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.best_location') }}</span>
                            <strong>{{ $bestLocation['location'] ?? '-' }}</strong>
                            <small>{{ $bestLocation ? $money($bestLocation['monthNet']) : $money(0) }}</small>
                        </div>
                        <div class="hero-metric">
                            <span class="card-kicker">{{ __('app.platform.view.highest_growth') }}</span>
                            <strong>{{ $highestGrowthLocation['location'] ?? '-' }}</strong>
                            <small>{{ $highestGrowthLocation ? $percent($highestGrowthLocation['growthPercent']) : $percent(0) }}</small>
                        </div>
                    </div>
                </div>

                <section class="kpi-strip">
                    <div class="panel dashboard-card metric-card">
                        <div><p class="metric-label">{{ __('app.platform.view.total_locations') }}</p><p class="metric-value">{{ $count($geographicRows->count()) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.location') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div><p class="metric-label">{{ __('app.platform.view.total_tenants') }}</p><p class="metric-value">{{ $count($geographicRows->sum('tenantCount')) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.geographic_summary') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-cyan">
                        <div><p class="metric-label">{{ __('app.platform.view.active_tenants') }}</p><p class="metric-value">{{ $count($geographicRows->sum('activeTenantCount')) }}</p></div>
                        <p class="metric-subtext">{{ __('app.platform.view.active_tenants') }}</p>
                    </div>
                    <div class="panel dashboard-card metric-card accent-slate">
                        <div><p class="metric-label">{{ __('app.platform.view.portfolio_growth') }}</p><p class="metric-value">{{ $highestGrowthLocation ? $percent($highestGrowthLocation['growthPercent']) : $percent(0) }}</p></div>
                        <p class="metric-subtext">{{ $highestGrowthLocation['location'] ?? '-' }}</p>
                    </div>
                </section>

                <section class="panel dashboard-card chart-card">
                    <div class="section-heading">
                        <div>
                            <h2>{{ __('app.platform.view.geographic_summary') }}</h2>
                            <p>{{ __('app.platform.view.realized_networth') }}</p>
                        </div>
                    </div>
                    <div class="dashboard-chart">
                        <canvas data-dashboard-chart="geographicNet"></canvas>
                    </div>
                </section>

                <section class="location-card-grid">
                    @forelse ($geographicRows->take(3) as $location)
                        <article class="location-card">
                            <div>
                                <h3>{{ $location['location'] }}</h3>
                                <span class="badge">{{ $percent($location['growthPercent']) }}</span>
                            </div>
                            <dl>
                                <div><dt>{{ __('app.platform.view.total_tenants') }}</dt><dd>{{ $count($location['tenantCount']) }}</dd></div>
                                <div><dt>{{ __('app.platform.view.realized_networth') }}</dt><dd>{{ $money($location['monthNet']) }}</dd></div>
                                <div><dt>{{ __('app.platform.view.best_tenant') }}</dt><dd>{{ $location['bestTenant'] }}</dd></div>
                            </dl>
                        </article>
                    @empty
                        <section class="panel dashboard-card summary-card">
                            <p class="muted">{{ __('app.common.view.empty.no_records') }}</p>
                        </section>
                    @endforelse
                </section>

                <section class="panel dashboard-card summary-card">
                    <div class="section-heading">
                        <div>
                            <h2>{{ __('app.platform.view.geographic_summary') }}</h2>
                            <p>{{ __('app.platform.view.location') }}</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>{{ __('app.platform.view.location') }}</th>
                                <th class="numeric">{{ __('app.platform.view.total_tenants') }}</th>
                                <th class="numeric">{{ __('app.platform.view.active_tenants') }}</th>
                                <th class="numeric">{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }}</th>
                                <th class="numeric">{{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}</th>
                                <th class="numeric">{{ __('app.platform.view.realized_networth') }}</th>
                                <th class="numeric">{{ __('app.platform.view.portfolio_growth') }}</th>
                                <th>{{ __('app.platform.view.best_tenant') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($summary['geographic_summary'] as $location)
                                <tr>
                                    <td data-label="{{ __('app.platform.view.location') }}">{{ $location['location'] }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.total_tenants') }}">{{ $count($location['tenantCount']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.active_tenants') }}">{{ $count($location['activeTenantCount']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.period_income', ['period' => $periodLabel]) }}">{{ $money($location['monthIncome']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.period_expense', ['period' => $periodLabel]) }}">{{ $money($location['monthExpense']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.realized_networth') }}">{{ $money($location['monthNet']) }}</td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.portfolio_growth') }}">{{ $percent($location['growthPercent']) }}</td>
                                    <td data-label="{{ __('app.platform.view.best_tenant') }}">{{ $location['bestTenant'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8">{{ __('app.common.view.empty.no_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>
        </div>

        <script type="application/json" id="platform-dashboard-chart-data">
            @json($summary['charts'])
        </script>
    @endif

    <script>
        (() => {
            const select = document.querySelector('[data-dashboard-time-filter]');
            const dateFields = document.querySelectorAll('[data-dashboard-custom-date]');
            const toggleDateFields = () => {
                const isCustom = select?.value === 'custom';
                dateFields.forEach((field) => {
                    field.style.display = isCustom ? 'grid' : 'none';
                    field.querySelector('input').disabled = !isCustom;
                });
            };

            select?.addEventListener('change', toggleDateFields);
            toggleDateFields();
        })();

        (() => {
            const root = document.querySelector('[data-dashboard-tabs]');

            if (!root) {
                return;
            }

            const tabs = root.querySelectorAll('[data-dashboard-tab]');
            const panels = root.querySelectorAll('[data-dashboard-panel]');

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    const selected = tab.dataset.dashboardTab;

                    tabs.forEach((item) => {
                        item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
                    });

                    panels.forEach((panel) => {
                        panel.hidden = panel.dataset.dashboardPanel !== selected;
                    });

                    window.dispatchEvent(new Event('platform-dashboard:tab-changed'));
                    window.dispatchEvent(new Event('resize'));
                });
            });
        })();
    </script>
@endsection
