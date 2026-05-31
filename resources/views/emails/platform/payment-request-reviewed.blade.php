<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">Payment request {{ $decision }}</h2>
    <p>Hello {{ $paymentRequest->platformUser?->name ?? 'there' }},</p>
    <p>Your payment request has been {{ $decision }}.</p>
    <ul>
        <li><strong>Payment:</strong> {{ $paymentRequest->code }}</li>
        <li><strong>Tenant request:</strong> {{ $paymentRequest->tenantRequest?->code ?? '-' }}</li>
        <li><strong>Tenant:</strong> {{ $paymentRequest->tenant?->name ?? '-' }} ({{ $paymentRequest->tenant?->tenant_code ?? '-' }})</li>
        <li><strong>Amount:</strong> {{ $paymentRequest->amount }} {{ $paymentRequest->currency }}</li>
    </ul>
    @if ($paymentRequest->tenantRequest?->admin_review_note)
        <p><strong>Admin note:</strong> {{ $paymentRequest->tenantRequest->admin_review_note }}</p>
    @endif
    <p style="margin-bottom:0;">
        @if ($decision === 'approved')
            Your tenant license has been updated.
        @else
            Please review the admin note and submit a new payment request if needed.
        @endif
    </p>
</div>
</body>
</html>
