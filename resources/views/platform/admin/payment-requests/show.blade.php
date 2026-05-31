@extends('platform.admin.layouts.app')

@section('title', 'Payment Request Detail')
@section('pageTitle', 'Payment Request Detail')
@section('pageDescription', 'Review submitted payment evidence and accept or reject the related tenant request.')

@section('pageAction')
    <a href="{{ route('admin.payment-requests.index') }}" class="button secondary">Back</a>
@endsection

@section('content')
    <section class="grid two">
        <div class="panel">
            <p class="metric-label">Payment</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Submitted</th><td>{{ $payment->submitted_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>User</th><td>{{ $payment->platformUser?->name ?? '-' }} ({{ $payment->platformUser?->email ?? '-' }})</td></tr>
                    <tr><th>Tenant</th><td>{{ $payment->tenant?->name ?? '-' }}</td></tr>
                    <tr><th>Amount</th><td>{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td></tr>
                    <tr><th>Reference</th><td>{{ $payment->payment_reference ?? '-' }}</td></tr>
                    <tr><th>Payment Status</th><td><span class="badge">{{ $payment->status }}</span></td></tr>
                    <tr><th>Note</th><td>{{ $payment->note ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <p class="metric-label">Tenant Request</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Type</th><td>{{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}</td></tr>
                    <tr><th>Requested Plan</th><td>{{ $payment->tenantRequest?->requested_plan_type ?? '-' }}</td></tr>
                    <tr><th>Extension</th><td>{{ $payment->tenantRequest?->extension_months ? $payment->tenantRequest->extension_months.' months' : '-' }}</td></tr>
                    <tr><th>Request Status</th><td><span class="badge">{{ $payment->tenantRequest?->request_status ?? '-' }}</span></td></tr>
                    <tr><th>Current Plan</th><td>{{ $payment->tenant?->license?->plan_type ?? '-' }}</td></tr>
                    <tr><th>Current Expiry</th><td>{{ $payment->tenant?->license?->expires_at?->format('Y-m-d') ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 16px;">
        <p class="metric-label">Attachments</p>
        @if ($payment->attachments->isEmpty())
            <p class="muted">No attachments uploaded.</p>
        @else
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <thead>
                    <tr>
                        <th>File</th>
                        <th>Type</th>
                        <th>Uploaded</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($payment->attachments as $attachment)
                        <tr>
                            <td>
                                <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" rel="noopener">
                                    {{ $attachment->file_path }}
                                </a>
                            </td>
                            <td>{{ $attachment->file_type }}</td>
                            <td>{{ $attachment->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($payment->tenantRequest?->request_status === 'pending_approval')
        <section class="grid two" style="margin-top: 16px;">
            <form class="panel" method="POST" action="{{ route('admin.payment-requests.accept', $payment->id) }}">
                @csrf
                <label for="accept_note">Accept Note</label>
                <textarea id="accept_note" name="admin_review_note">{{ old('admin_review_note') }}</textarea>
                @error('admin_review_note') <div class="field-error">{{ $message }}</div> @enderror
                <div style="margin-top: 14px;">
                    <button type="submit" class="button primary">Accept</button>
                </div>
            </form>

            <form class="panel" method="POST" action="{{ route('admin.payment-requests.reject', $payment->id) }}">
                @csrf
                <label for="reject_note">Reject Note</label>
                <textarea id="reject_note" name="admin_review_note">{{ old('admin_review_note') }}</textarea>
                @error('admin_review_note') <div class="field-error">{{ $message }}</div> @enderror
                <div style="margin-top: 14px;">
                    <button type="submit" class="button danger">Reject</button>
                </div>
            </form>
        </section>
    @endif
@endsection
