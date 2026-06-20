@props(['payment'])

<article class="lp-billing-card {{ $payment->status === 'draft' ? 'is-actionable' : '' }}">
    <div class="lp-billing-card-head">
        <div>
            <p class="mobile-card-kicker">{{ $payment->submitted_at?->format('Y-m-d') ?? '-' }}</p>
            <h2 class="mobile-card-title">{{ $payment->tenant?->name ?? '-' }}</h2>
        </div>
        <span class="badge">{{ $payment->status }}</span>
    </div>

    <div class="lp-billing-card-body">
        <div class="detail-row">
            <span>{{ __('app.common.view.labels.request') }}</span>
            <strong>
                {{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}
                @if ($payment->tenantRequest?->extension_months)
                    ({{ $payment->tenantRequest->extension_months }} mo)
                @endif
            </strong>
        </div>
        <div class="detail-row">
            <span>{{ __('app.common.view.labels.amount') }}</span>
            <strong>{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</strong>
        </div>
        <div class="detail-row">
            <span>{{ __('app.billing.view.reference') }}</span>
            <strong>{{ $payment->payment_reference ?? '-' }}</strong>
        </div>
        <div class="lp-status-grid">
            <div class="lp-status-box">
                <p class="field-kicker">{{ __('app.billing.view.request_status') }}</p>
                <strong>{{ $payment->tenantRequest?->request_status ?? '-' }}</strong>
            </div>
            <div class="lp-status-box">
                <p class="field-kicker">{{ __('app.common.view.labels.reviewed') }}</p>
                <strong>{{ $payment->reviewed_at?->format('Y-m-d') ?? '-' }}</strong>
            </div>
        </div>

        @if ($payment->status === 'draft' && $payment->tenant_request_id)
            <button type="button" class="button primary" data-open-dialog="payment-dialog-{{ $payment->id }}">{{ __('app.billing.view.submit_attachment') }}</button>
        @else
            <div class="detail-row">
                <span>{{ __('app.billing.view.attachment') }}</span>
                <strong>{{ trans_choice('app.billing.view.files_count', $payment->attachments->count(), ['count' => $payment->attachments->count()]) }}</strong>
            </div>
        @endif
    </div>
</article>
