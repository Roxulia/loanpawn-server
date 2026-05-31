@extends('platform.admin.layouts.app')

@section('title', 'Admin Dashboard')
@section('pageTitle', 'Admin Dashboard')
@section('pageDescription', 'Platform-wide tenant, user, billing, and payment approval summary.')

@section('content')
    <section class="grid kpi">
        <div class="panel">
            <p class="metric-label">Tenants</p>
            <p class="metric-value">{{ $summary['tenant_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Platform Users</p>
            <p class="metric-value">{{ $summary['platform_user_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Payment Requests</p>
            <p class="metric-value">{{ $summary['pending_payment_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Approved Total</p>
            <p class="metric-value">{{ number_format($summary['approved_total'], 0) }}</p>
        </div>
    </section>
@endsection
