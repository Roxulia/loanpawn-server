@extends('platform.layouts.app')

@section('title', 'Platform Dashboard')
@section('pageTitle', 'Dashboard')
@section('pageDescription', 'Summary of created tenants, resource usage, license attention, and payment activity.')

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">Create Tenant</a>
@endsection

@section('content')
    @if (! $summary['has_data'])
        <section class="panel empty-state">
            <div>
                <h2>No tenant data yet</h2>
                <p>Create your first tenant to start tracking KPIs, branding setup, license health, resource usage, and billing requests.</p>
                <a href="{{ route('platform.tenants.create') }}" class="button primary">Create New Tenant</a>
            </div>
        </section>
    @else
        <section class="grid kpi">
            <div class="panel">
                <p class="metric-label">Total Tenants</p>
                <p class="metric-value">{{ $summary['tenant_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">Active Tenants</p>
                <p class="metric-value">{{ $summary['active_tenant_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">Expiring Licenses</p>
                <p class="metric-value">{{ $summary['expiring_license_count'] }}</p>
            </div>
            <div class="panel">
                <p class="metric-label">Pending Payments</p>
                <p class="metric-value">{{ $summary['pending_payment_count'] }}</p>
            </div>
        </section>

        <section class="grid two" style="margin-top: 16px;">
            <div class="panel">
                <p class="metric-label">Resource Usage</p>
                <p class="metric-value">{{ $summary['resource_usage_percent'] }}%</p>
                <p class="muted">Tenants with branding, contact, or settings configured.</p>
            </div>
            <div class="panel">
                <p class="metric-label">Top Performing Tenants</p>
                <div class="table-wrap" style="margin-top: 10px;">
                    <table>
                        <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Plan</th>
                            <th>Settings</th>
                            <th>Status</th>
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
