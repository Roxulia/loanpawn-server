@extends('platform.layouts.app')

@section('title', __('app.platform.view.dashboard'))
@section('pageTitle', __('app.platform.view.dashboard'))
@section('pageDescription', __('app.platform.view.dashboard_description'))

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
@endsection

@section('content')
    @if (! $summary['has_data'])
        <section class="panel empty-state">
            <div>
                <h2>{{ __('app.common.view.empty.no_tenant_data') }}</h2>
                <p>{{ __('app.platform.view.create_first_tenant_description') }}</p>
                <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
            </div>
        </section>
    @else
        <section class="grid kpi">
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.total_tenants') }}</p>
                <p class="metric-value">{{ $summary['tenant_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.active_tenants') }}</p>
                <p class="metric-value">{{ $summary['active_tenant_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.expiring_licenses') }}</p>
                <p class="metric-value">{{ $summary['expiring_license_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.pending_payments') }}</p>
                <p class="metric-value">{{ $summary['pending_payment_count'] }}</p>
            </div>
        </section>

        <section class="grid two" style="margin-top: 16px;">
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.resource_usage') }}</p>
                <p class="metric-value">{{ $summary['resource_usage_percent'] }}%</p>
                <p class="muted">{{ __('app.platform.view.resource_usage_description') }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">{{ __('app.platform.view.top_performing_tenants') }}</p>
                <div class="table-wrap" style="margin-top: 10px;">
                    <table>
                        <thead>
                        <tr>
                            <th>{{ __('app.common.view.labels.tenant') }}</th>
                            <th>{{ __('app.common.view.labels.plan') }}</th>
                            <th>{{ __('app.platform.view.settings') }}</th>
                            <th>{{ __('app.common.view.labels.status') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($summary['top_tenants'] as $tenant)
                            <tr>
                                <td>{{ $tenant->name }}</td>
                                <td>{{ $tenant->license?->plan_type ?? 'trial' }}</td>
                                <td>{{ $tenant->settings_count }}</td>
                                <td><span class="badge">{{ $tenant->status }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
@endsection
