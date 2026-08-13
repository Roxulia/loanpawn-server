@extends('platform.admin.layouts.app')

@section('title', 'Admin Dashboard')
@section('pageTitle', 'Admin Dashboard')
@section('pageDescription', 'Platform-wide tenant, user, billing, and payment approval summary.')

@section('content')
    <div class="admin-stack admin-dashboard-page">
    <section class="grid kpi" aria-label="Platform summary">
        <div class="panel">
            <p class="metric-label">Total Tenants</p>
            <p class="metric-value">{{ $summary['tenant_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Platform Users</p>
            <p class="metric-value">{{ $summary['platform_user_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Pending Payments</p>
            <p class="metric-value">{{ $summary['pending_payment_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Approved Total</p>
            <p class="metric-value">{{ number_format($summary['approved_total'], 0) }}</p>
        </div>
    </section>
    <section class="panel admin-dashboard-welcome">
        <div class="admin-section-heading">
            <div><p class="admin-section-kicker">Operations</p><h2>Platform at a glance</h2><p>Use the categorized workspace to manage organizations, entitlements, finance configuration, billing approvals, and support.</p></div>
        </div>
        <div class="admin-quick-links">
            <a href="{{ route('admin.tenants.index') }}"><strong>Organization</strong><span>Review tenants and license status</span></a>
            <a href="{{ route('admin.package-flags.index') }}"><strong>Entitlements</strong><span>Control plans and feature availability</span></a>
            <a href="{{ route('admin.payment-requests.index') }}"><strong>Approvals</strong><span>Process pending payment requests</span></a>
        </div>
    </section>
    </div>
@endsection
