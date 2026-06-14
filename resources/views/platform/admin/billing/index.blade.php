@extends('platform.admin.layouts.app')

@section('title', 'Admin Billing Management')
@section('pageTitle', 'Billing Management')
@section('pageDescription', 'Review all manual payments, approval counts, and total approved billings.')

@section('content')
    <section class="grid kpi">
        <div class="panel">
            <p class="metric-label">Pending Approval</p>
            <p class="metric-value">{{ $billing['pending_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Approved Payments</p>
            <p class="metric-value">{{ $billing['approved_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Approved Total</p>
            <p class="metric-value">{{ number_format($billing['approved_total'], 0) }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">Currency</p>
            <p class="metric-value">MMK</p>
        </div>
    </section>

    <section class="panel" style="margin-top: 16px;">
        @if ($billing['payments']->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No billing records</h2>
                    <p class="muted">Manual payment records will appear here after platform users submit payment attachments.</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>User</th>
                        <th>Tenant</th>
                        <th>Request</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Request</th>
                        <th>Reviewed</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($billing['payments'] as $payment)
                        <tr>
                            <td data-label="Submitted">{{ $payment->submitted_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="User">{{ $payment->platformUser?->name ?? '-' }}</td>
                            <td data-label="Tenant">{{ $payment->tenant?->name ?? '-' }}</td>
                            <td data-label="Request">{{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}</td>
                            <td data-label="Amount">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td>
                            <td data-label="Payment"><span class="badge">{{ $payment->status }}</span></td>
                            <td data-label="Request"><span class="badge">{{ $payment->tenantRequest?->request_status ?? '-' }}</span></td>
                            <td data-label="Reviewed">{{ $payment->reviewed_at?->format('Y-m-d') ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $billing['payments']->links() }}
            </div>
        @endif
    </section>
@endsection
