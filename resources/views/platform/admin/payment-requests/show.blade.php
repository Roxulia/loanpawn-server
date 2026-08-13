@extends('platform.admin.layouts.app')

@section('title', 'Payment Request Detail')
@section('pageTitle', 'Payment Request Detail')
@section('pageDescription', 'Review submitted payment evidence and accept or reject the related tenant request.')

@section('pageAction')
    <a href="{{ route('admin.payment-requests.index') }}" class="button secondary">Back</a>
@endsection

@section('content')
    <div class="admin-stack admin-payment-review-page">
    <section class="grid two">
        <div class="panel">
            <p class="admin-section-kicker">Payment evidence</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Submitted</th><td data-label="Submitted">{{ $payment->submitted_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>User</th><td data-label="User">{{ $payment->platformUser?->name ?? '-' }} ({{ $payment->platformUser?->email ?? '-' }})</td></tr>
                    <tr><th>Tenant</th><td data-label="Tenant">{{ $payment->tenant?->name ?? '-' }}</td></tr>
                    <tr><th>Amount</th><td data-label="Amount">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td></tr>
                    <tr><th>Reference</th><td data-label="Reference">{{ $payment->payment_reference ?? '-' }}</td></tr>
                    <tr><th>Payment Status</th><td data-label="Payment Status"><span class="badge">{{ $payment->status }}</span></td></tr>
                    <tr><th>Note</th><td data-label="Note">{{ $payment->note ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <p class="admin-section-kicker">Requested change</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Type</th><td data-label="Type">{{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}</td></tr>
                    <tr><th>Requested Plan</th><td data-label="Requested Plan">{{ $payment->tenantRequest?->requested_plan_type ?? '-' }}</td></tr>
                    <tr><th>Extension</th><td data-label="Extension">{{ $payment->tenantRequest?->extension_months ? $payment->tenantRequest->extension_months.' months' : '-' }}</td></tr>
                    <tr><th>Request Status</th><td data-label="Request Status"><span class="badge">{{ $payment->tenantRequest?->request_status ?? '-' }}</span></td></tr>
                    <tr><th>Current Plan</th><td data-label="Current Plan">{{ $payment->tenant?->license?->plan_type ?? '-' }}</td></tr>
                    <tr><th>Current Expiry</th><td data-label="Current Expiry">{{ $payment->tenant?->license?->expires_at?->format('Y-m-d') ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="admin-section-heading"><div><p class="admin-section-kicker">Evidence</p><h2>Attachments</h2><p>Files submitted with this payment request.</p></div></div>
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
                            <td data-label="File">
                                <a href="{{ URL::temporarySignedRoute('admin.payment-requests.attachments.download', now()->addMinutes(10), ['paymentRequest' => $payment->id, 'attachment' => $attachment->id]) }}">
                                    Download attachment - {{ basename($attachment->file_path) }}
                                </a>
                            </td>
                            <td data-label="Type">{{ $attachment->file_type }}</td>
                            <td data-label="Uploaded">{{ $attachment->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($payment->tenantRequest?->request_status === 'pending_approval')
        <section class="grid two admin-decision-grid">
            <form class="panel admin-decision-card approve" method="POST" action="{{ route('admin.payment-requests.accept', $payment->id) }}">
                @csrf
                <label for="accept_note">Accept Note</label>
                <textarea id="accept_note" name="admin_review_note">{{ old('admin_review_note') }}</textarea>
                @error('admin_review_note') <div class="field-error">{{ $message }}</div> @enderror
                <div style="margin-top: 14px;">
                    <button type="submit" class="button primary">Accept</button>
                </div>
            </form>

            <form class="panel admin-decision-card reject" method="POST" action="{{ route('admin.payment-requests.reject', $payment->id) }}">
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
    </div>
@endsection
