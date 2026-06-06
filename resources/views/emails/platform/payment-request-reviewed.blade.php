<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">{{ __('app.billing.view.payment_request_decision', ['decision' => $decision]) }}</h2>
    <p>{{ __('app.common.view.greeting', ['name' => $paymentRequest->platformUser?->name ?? __('app.common.view.there')]) }}</p>
    <p>{{ __('app.billing.view.payment_request_reviewed_body', ['decision' => $decision]) }}</p>
    <ul>
        <li><strong>{{ __('app.common.view.labels.payment') }}:</strong> {{ $paymentRequest->code }}</li>
        <li><strong>{{ __('app.billing.view.tenant_request') }}:</strong> {{ $paymentRequest->tenantRequest?->code ?? '-' }}</li>
        <li><strong>{{ __('app.common.view.labels.tenant') }}:</strong> {{ $paymentRequest->tenant?->name ?? '-' }} ({{ $paymentRequest->tenant?->tenant_code ?? '-' }})</li>
        <li><strong>{{ __('app.common.view.labels.amount') }}:</strong> {{ $paymentRequest->amount }} {{ $paymentRequest->currency }}</li>
    </ul>
    @if ($paymentRequest->tenantRequest?->admin_review_note)
        <p><strong>{{ __('app.common.view.labels.admin_note') }}:</strong> {{ $paymentRequest->tenantRequest->admin_review_note }}</p>
    @endif
    <p style="margin-bottom:0;">
        @if ($decision === 'approved')
            {{ __('app.billing.view.tenant_license_updated') }}
        @else
            {{ __('app.billing.view.review_note_and_resubmit') }}
        @endif
    </p>
</div>
</body>
</html>
