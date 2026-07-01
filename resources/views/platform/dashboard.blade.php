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
        'time_filter' => request('time_filter', 'this_month'),
        'startAt' => request('start_at'),
        'endAt' => request('end_at'),
    ];
    $selectedTimeFilter = $filter['time_filter'] ?? 'this_month';
    $selectedStartDate = isset($filter['startAt']) ? \Carbon\CarbonImmutable::parse($filter['startAt'])->toDateString() : '';
    $selectedEndDate = isset($filter['endAt']) ? \Carbon\CarbonImmutable::parse($filter['endAt'])->toDateString() : '';
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
    $expiringContractRiskRows = collect($summary['expiring_contract_risks'] ?? []);
    $topUsageRows = collect($summary['package_usage']['topUsage'] ?? [])->take(5);
    $geographicRows = collect($summary['geographic_summary'] ?? []);
    $financialPerformance = $summary['financial_performance'] ?? [
        'kpis' => [],
        'tenantRows' => [],
        'usageItems' => [],
        'insights' => [],
    ];
    $executiveOverview = $summary['executive_overview'] ?? [
        'kpis' => [],
        'benchmarkRows' => [],
        'priorityEvents' => [],
    ];
    $bestLocation = $geographicRows->sortByDesc('monthNet')->first();
    $highestGrowthLocation = $geographicRows->sortByDesc('growthPercent')->first();
@endphp

@section('content')
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
                <input type="date" name="start_at" value="{{ $selectedStartDate }}">
            </label>
            <label data-dashboard-custom-date>
                <span>{{ __('app.platform.view.end_date') }}</span>
                <input type="date" name="end_at" value="{{ $selectedEndDate }}">
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
                            <small>
                                {{ __('app.platform.view.slips') }} {{ $count($summary['tenant_counts']['slipCurrentCount']) }}/{{ $limit($summary['tenant_counts']['slipMaxCount']) }}
                                ·
                                {{ __('app.platform.view.staff') }} {{ $count($summary['tenant_counts']['staffCurrentCount']) }}/{{ $limit($summary['tenant_counts']['staffMaxCount']) }}
                            </small>
                        </div>
                    </div>
                </div>

                <section class="overview-kpi-grid">
                    @foreach ($executiveOverview['kpis'] as $kpi)
                        <x-platform.dashboard.metric-card
                            :label="__('app.platform.view.'.$kpi['labelKey'])"
                            :value="$kpi['displayType'] === 'money' ? $money($kpi['value']) : ($kpi['displayType'] === 'percent' ? $percent($kpi['value']) : $count($kpi['value']))"
                            :subtext="__('app.platform.view.'.$kpi['subtextKey'], $kpi['subtextParams'])"
                            :trend="$kpi['trend']"
                            :tone="$kpi['tone']"
                            :bars="$kpi['bars']"
                        />
                    @endforeach
                </section>

                <div class="overview-bento-grid">
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.performance_benchmark') }}</h2>
                                <p>{{ __('app.platform.view.current_vs_previous_period') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="overviewBenchmark"></canvas>
                        </div>
                    </div>

                    <div class="overview-side-stack">
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
                    </div>
                </div>

                <x-platform.dashboard.summary-card :title="__('app.platform.view.high_priority_tenant_events')" :description="__('app.platform.view.risk')">
                    <div class="table-wrap overview-event-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('app.common.view.labels.tenant') }}</th>
                                    <th>{{ __('app.common.view.labels.status') }}</th>
                                    <th class="numeric">{{ __('app.platform.view.period_net', ['period' => $periodLabel]) }}</th>
                                    <th>{{ __('app.platform.view.risk') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($executiveOverview['priorityEvents'] as $event)
                                <tr>
                                    <td data-label="{{ __('app.common.view.labels.tenant') }}"><strong>{{ $event['tenant'] }}</strong></td>
                                    <td data-label="{{ __('app.common.view.labels.status') }}">
                                        <x-platform.dashboard.status-chip :tone="$event['statusTone']">
                                            {{ __('app.platform.view.'.$event['statusKey']) }}
                                        </x-platform.dashboard.status-chip>
                                    </td>
                                    <td class="numeric" data-label="{{ __('app.platform.view.period_net', ['period' => $periodLabel]) }}">{{ $money($event['impact']) }}</td>
                                    <td data-label="{{ __('app.platform.view.risk') }}">{{ $event['detail'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4">{{ __('app.common.view.empty.no_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-platform.dashboard.summary-card>
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

                <section class="financial-kpi-grid">
                    @foreach ($financialPerformance['kpis'] as $kpi)
                        <x-platform.dashboard.metric-card
                            :label="__('app.platform.view.'.$kpi['labelKey'])"
                            :value="$money($kpi['value'])"
                            :subtext="__('app.platform.view.'.$kpi['subtextKey'], ['percent' => $percent($kpi['progressPercent'])])"
                            :trend="$kpi['trend']"
                            :tone="$kpi['tone']"
                            :progress="$kpi['progressPercent']"
                        />
                    @endforeach
                </section>

                <div class="financial-main-grid">
                    <x-platform.dashboard.summary-card :title="__('app.platform.view.portfolio_financial_summary')" :description="__('app.platform.view.net_operating_income')">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{{ __('app.common.view.labels.tenant') }}</th>
                                        <th class="numeric">{{ __('app.platform.view.total_portfolio_revenue') }}</th>
                                        <th class="numeric">{{ __('app.platform.view.operating_expenses') }}</th>
                                        <th class="numeric">{{ __('app.platform.view.net_operating_income') }}</th>
                                        <th class="numeric">{{ __('app.platform.view.net_margin') }}</th>
                                        <th>{{ __('app.common.view.labels.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse ($financialPerformance['tenantRows'] as $tenant)
                                    <tr>
                                        <td data-label="{{ __('app.common.view.labels.tenant') }}">
                                            <strong>{{ $tenant['name'] }}</strong><br>
                                            <span class="muted">{{ $tenant['location'] }}</span>
                                        </td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.total_portfolio_revenue') }}">{{ $money($tenant['revenue']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.operating_expenses') }}">{{ $money($tenant['expense']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.net_operating_income') }}">{{ $money($tenant['noi']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.net_margin') }}">{{ $percent($tenant['marginPercent']) }}</td>
                                        <td data-label="{{ __('app.common.view.labels.status') }}">
                                            <x-platform.dashboard.status-chip :tone="$tenant['statusTone']">
                                                {{ __('app.platform.view.'.$tenant['statusKey']) }}
                                            </x-platform.dashboard.status-chip>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6">{{ __('app.common.view.empty.no_records') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-platform.dashboard.summary-card>

                    <x-platform.dashboard.summary-card :title="__('app.platform.view.package_usage')" :description="__('app.platform.view.current_vs_limit')">
                        <div class="usage-list">
                            @foreach ($financialPerformance['usageItems'] as $item)
                                <x-platform.dashboard.progress-item
                                    :label="__('app.platform.view.'.$item['labelKey'])"
                                    :current="$count($item['current'])"
                                    :limit="$limit($item['limit'])"
                                    :percent="$item['percent']"
                                    :tone="$item['tone']"
                                />
                            @endforeach
                        </div>
                    </x-platform.dashboard.summary-card>
                </div>

                <div class="financial-chart-grid">
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.income_vs_expense') }}</h2>
                                <p>{{ __('app.platform.view.comparative_tenant_chart') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="tenantIncomeExpense"></canvas>
                        </div>
                    </div>

                    <x-platform.dashboard.summary-card :title="__('app.platform.view.income_streams')" :description="__('app.platform.view.expense_streams')">
                        <div class="compact-list">
                            @forelse ($summary['income_streams'] as $stream)
                                <x-platform.dashboard.compact-row :label="$stream['name'].' ('.$count($stream['transactionCount']).')'" :value="$money($stream['total'])" />
                            @empty
                                <p class="muted">{{ __('app.common.view.empty.no_records') }}</p>
                            @endforelse
                        </div>
                    </x-platform.dashboard.summary-card>
                </div>

                <section class="financial-insight-grid">
                    @foreach ($financialPerformance['insights'] as $insight)
                        <x-platform.dashboard.insight-card
                            :title="__('app.platform.view.'.$insight['titleKey'])"
                            :body="__('app.platform.view.'.$insight['bodyKey'], $insight['bodyParams'])"
                            :icon="$insight['icon']"
                            :tone="$insight['tone']"
                        />
                    @endforeach
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
                                <h2>{{ __('app.platform.view.slip_package_usage') }}</h2>
                                <p>{{ __('app.platform.view.current_month_slips') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="slipPackageUsage"></canvas>
                        </div>
                    </div>
                    <div class="panel dashboard-card chart-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.staff_package_usage') }}</h2>
                                <p>{{ __('app.platform.view.current_staff_count') }}</p>
                            </div>
                        </div>
                        <div class="dashboard-chart">
                            <canvas data-dashboard-chart="staffPackageUsage"></canvas>
                        </div>
                    </div>
                </section>

                <section class="grid two">
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
                    <div class="panel dashboard-card summary-card">
                        <div class="section-heading">
                            <div>
                                <h2>{{ __('app.platform.view.expiring_contract_risk') }}</h2>
                                <p>{{ __('app.platform.view.next_7_days') }}</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>{{ __('app.common.view.labels.tenant') }}</th>
                                    <th class="numeric">{{ __('app.platform.view.contracts') }}</th>
                                    <th class="numeric">{{ __('app.platform.view.collectible') }}</th>
                                    <th class="numeric">{{ __('app.platform.view.minimum_retail') }}</th>
                                    <th class="numeric">{{ __('app.platform.view.risk_value') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($expiringContractRiskRows as $tenant)
                                    <tr>
                                        <td data-label="{{ __('app.common.view.labels.tenant') }}">
                                            <strong>{{ $tenant['name'] }}</strong>
                                            <div class="muted">{{ __('app.platform.view.nearest_expiry') }} {{ $tenant['nearestExpireDate'] ?? '-' }}</div>
                                        </td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.contracts') }}">{{ $count($tenant['contractCount']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.collectible') }}">{{ $money($tenant['collectibleTotal']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.minimum_retail') }}">{{ $money($tenant['minimumRetailTotal']) }}</td>
                                        <td class="numeric" data-label="{{ __('app.platform.view.risk_value') }}">{{ $percent($tenant['riskValue']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">{{ __('app.common.view.empty.no_records') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
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
