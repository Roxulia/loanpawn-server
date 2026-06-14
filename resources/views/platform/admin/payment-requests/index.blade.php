@extends('platform.admin.layouts.app')

@section('title', 'Payment Requests')
@section('pageTitle', 'Payment Requests')
@section('pageDescription', 'Review manual payments whose tenant request status is pending approval.')

@section('content')
    <section class="panel">
        @if ($payments->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No pending approvals</h2>
                    <p class="muted">Submitted payment requests waiting for admin approval will appear here.</p>
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
                        <th>Reference</th>
                        <th>Attachments</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td data-label="Submitted">{{ $payment->submitted_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="User">{{ $payment->platformUser?->name ?? '-' }}</td>
                            <td data-label="Tenant">{{ $payment->tenant?->name ?? '-' }}</td>
                            <td data-label="Request">
                                {{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}
                                @if ($payment->tenantRequest?->extension_months)
                                    <span class="muted">({{ $payment->tenantRequest->extension_months }} mo)</span>
                                @endif
                            </td>
                            <td data-label="Amount">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td>
                            <td data-label="Reference">{{ $payment->payment_reference ?? '-' }}</td>
                            <td data-label="Attachments">{{ $payment->attachments->count() }}</td>
                            <td data-label="">
                                <a href="{{ route('admin.payment-requests.show', $payment->id) }}" class="button secondary">View</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $payments->links() }}
            </div>
        @endif
    </section>
@endsection
