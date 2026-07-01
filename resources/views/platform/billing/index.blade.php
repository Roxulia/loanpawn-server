@extends('platform.layouts.app')

@section('title', __('app.platform.view.billing_management'))
@section('pageTitle', __('app.platform.view.billing_management'))
@section('pageDescription', __('app.billing.view.billing_management_description'))

@section('content')
    <style>
        .platform-dialog {
            position: fixed;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            margin: 0;
        }

        .dialog-close.icon-only {
            width: 38px;
            height: 38px;
            padding: 0;
            font-size: 24px;
            line-height: 1;
        }
    </style>

    <section class="grid kpi billing-kpi">
        <div class="panel">
            <p class="metric-label">{{ __('app.billing.view.pending_requests') }}</p>
            <p class="metric-value">{{ $billing['pending_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">{{ __('app.billing.view.approved_payments') }}</p>
            <p class="metric-value">{{ $billing['approved_count'] }}</p>
        </div>
        <div class="panel billing-total-panel">
            <div>
                <p class="metric-label">{{ __('app.billing.view.approved_total') }}</p>
                <p class="metric-value">{{ number_format($billing['approved_total'], 0) }}</p>
            </div>
            <div class="billing-currency">
                <p class="metric-label">{{ __('app.common.view.labels.currency') }}</p>
                <p>MMK</p>
            </div>
        </div>
    </section>

    @if ($billing['payments']->total() === 0)
        <section class="panel" style="margin-top: 16px;">
            <div class="empty-state">
                <div>
                    <h2>{{ __('app.billing.view.no_billing_records') }}</h2>
                    <p>{{ __('app.billing.view.no_billing_records_user_description') }}</p>
                </div>
            </div>
        </section>
    @else
        <section class="mobile-only-section" style="margin-top: 16px;">
            <div style="margin-bottom: 12px; display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                <p class="section-kicker">{{ __('app.platform.view.billing_management') }}</p>
                <span class="badge">Recent Activity</span>
            </div>

            <div class="mobile-card-list">
                @foreach ($billing['payments'] as $payment)
                    <x-platform.billing-card :payment="$payment" />
                @endforeach
            </div>
        </section>

        <section class="panel desktop-table-panel">
            <div class="table-wrap">
                <h2 style="margin: 0 0 12px; color: var(--color-heading); font-size: 20px;">{{ __('app.platform.view.billing_management') }}</h2>
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('app.common.view.labels.submitted') }}</th>
                        <th>{{ __('app.common.view.labels.tenant') }}</th>
                        <th>{{ __('app.common.view.labels.request') }}</th>
                        <th>{{ __('app.billing.view.reference') }}</th>
                        <th>{{ __('app.common.view.labels.amount') }}</th>
                        <th>{{ __('app.billing.view.payment_status') }}</th>
                        <th>{{ __('app.billing.view.request_status') }}</th>
                        <th>{{ __('app.common.view.labels.reviewed') }}</th>
                        <th>{{ __('app.billing.view.attachment') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($billing['payments'] as $payment)
                        <tr>
                            <td data-label="{{ __('app.common.view.labels.submitted') }}">{{ $payment->submitted_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.tenant') }}">{{ $payment->tenant?->name ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.request') }}">
                                {{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}
                                @if ($payment->tenantRequest?->extension_months)
                                    <span class="muted">({{ $payment->tenantRequest->extension_months }} mo)</span>
                                @endif
                            </td>
                            <td data-label="{{ __('app.billing.view.reference') }}">{{ $payment->payment_reference ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.amount') }}">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td>
                            <td data-label="{{ __('app.billing.view.payment_status') }}"><span class="badge">{{ $payment->status }}</span></td>
                            <td data-label="{{ __('app.billing.view.request_status') }}"><span class="badge">{{ $payment->tenantRequest?->request_status ?? '-' }}</span></td>
                            <td data-label="{{ __('app.common.view.labels.reviewed') }}">{{ $payment->reviewed_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="{{ __('app.billing.view.attachment') }}">
                                @if ($payment->status === 'draft' && $payment->tenant_request_id)
                                    <button type="button" class="button secondary" data-open-dialog="payment-dialog-{{ $payment->id }}">{{ __('app.billing.view.submit_attachment') }}</button>
                                @else
                                    {{ trans_choice('app.billing.view.files_count', $payment->attachments->count(), ['count' => $payment->attachments->count()]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pagination">
            {{ $billing['payments']->links() }}
        </div>
    @endif

    @foreach ($billing['payments'] as $payment)
        @if ($payment->status === 'draft' && $payment->tenant_request_id)
            <dialog
                class="platform-dialog"
                id="payment-dialog-{{ $payment->id }}"
                @if ((int) session('open_payment_tenant_request_id') === (int) $payment->tenant_request_id) data-auto-open-payment-dialog @endif
            >
                <form method="POST" action="{{ route('platform.billing.payment.submit', $payment->tenant_request_id) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="update_key" value="{{ $payment->tenantRequest->update_key }}">
                    <div class="dialog-header">
                        <h2>{{ __('app.billing.view.submit_payment_attachment') }}</h2>
                        <button type="button" class="dialog-close icon-only" data-close-dialog="payment-dialog-{{ $payment->id }}" aria-label="{{ __('app.common.view.actions.close') }}">&times;</button>
                    </div>
                    <div class="grid">
                        <div>
                            <label>{{ __('app.common.view.labels.tenant') }}</label>
                            <input value="{{ $payment->tenant?->name ?? '-' }}" disabled>
                        </div>
                        <div>
                            <label>{{ __('app.common.view.labels.amount') }}</label>
                            <input value="{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}" disabled>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>{{ __('app.billing.view.active_payment_qr') }}</label>
                            @if ($billing['active_payment_qr'])
                                <div class="payment-qr-payment-panel">
                                    <img src="{{ route('platform.payment-qrs.image', $billing['active_payment_qr']->id) }}" alt="{{ __('app.billing.view.payment_qr') }}">
                                    <div>
                                        <strong>{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</strong>
                                        <p class="muted">{{ __('app.billing.view.active_payment_qr_description') }}</p>
                                    </div>
                                </div>
                            @else
                                <div class="payment-qr-empty">
                                    {{ __('app.billing.view.no_active_qr') }}
                                </div>
                            @endif
                        </div>
                        <div>
                            <label for="payment_reference_{{ $payment->id }}">{{ __('app.common.view.labels.payment_reference') }}</label>
                            <input id="payment_reference_{{ $payment->id }}" name="payment_reference" value="{{ old('payment_reference') }}">
                        </div>
                        <div>
                            <label for="payment_screenshot_{{ $payment->id }}">{{ __('app.billing.view.payment_attachment') }}</label>
                            <input id="payment_screenshot_{{ $payment->id }}" type="file" name="payment_screenshot" accept="image/*" required>
                            @error('payment_screenshot') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="payment_note_{{ $payment->id }}">{{ __('app.common.view.labels.note') }}</label>
                            <textarea id="payment_note_{{ $payment->id }}" name="note">{{ old('note') }}</textarea>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <button type="submit" class="button primary">{{ __('app.common.view.actions.submit_payment') }}</button>
                    </div>
                </form>
            </dialog>
        @endif
    @endforeach

    <script>
        document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.openDialog)?.showModal();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.closeDialog)?.close();
            });
        });

        document.querySelector('[data-auto-open-payment-dialog]')?.showModal();
    </script>
@endsection
