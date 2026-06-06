@extends('platform.layouts.app')

@section('title', __('app.platform.view.billing_management'))
@section('pageTitle', __('app.platform.view.billing_management'))
@section('pageDescription', __('app.billing.view.billing_management_description'))

@section('content')
    <section class="grid kpi">
        <div class="panel">
            <p class="metric-label">{{ __('app.billing.view.pending_requests') }}</p>
            <p class="metric-value">{{ $billing['pending_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">{{ __('app.billing.view.approved_payments') }}</p>
            <p class="metric-value">{{ $billing['approved_count'] }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">{{ __('app.billing.view.approved_total') }}</p>
            <p class="metric-value">{{ number_format($billing['approved_total'], 0) }}</p>
        </div>
        <div class="panel">
            <p class="metric-label">{{ __('app.common.view.labels.currency') }}</p>
            <p class="metric-value">MMK</p>
        </div>
    </section>

    <section class="panel" style="margin-top: 16px;">
        @if ($billing['payments']->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>{{ __('app.billing.view.no_billing_records') }}</h2>
                    <p>{{ __('app.billing.view.no_billing_records_user_description') }}</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
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
                            <td>{{ $payment->submitted_at?->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $payment->tenant?->name ?? '-' }}</td>
                            <td>
                                {{ str_replace('_', ' ', $payment->tenantRequest?->request_type ?? '-') }}
                                @if ($payment->tenantRequest?->extension_months)
                                    <span class="muted">({{ $payment->tenantRequest->extension_months }} mo)</span>
                                @endif
                            </td>
                            <td>{{ $payment->payment_reference ?? '-' }}</td>
                            <td>{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</td>
                            <td><span class="badge">{{ $payment->status }}</span></td>
                            <td><span class="badge">{{ $payment->tenantRequest?->request_status ?? '-' }}</span></td>
                            <td>{{ $payment->reviewed_at?->format('Y-m-d') ?? '-' }}</td>
                            <td>
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

            <div class="pagination">
                {{ $billing['payments']->links() }}
            </div>
        @endif
    </section>

    @foreach ($billing['payments'] as $payment)
        @if ($payment->status === 'draft' && $payment->tenant_request_id)
            <dialog class="platform-dialog" id="payment-dialog-{{ $payment->id }}">
                <form method="POST" action="{{ route('platform.billing.payment.submit', $payment->tenant_request_id) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="update_key" value="{{ $payment->tenantRequest->update_key }}">
                    <div class="dialog-header">
                        <h2>{{ __('app.billing.view.submit_payment_attachment') }}</h2>
                        <button type="button" class="dialog-close" data-close-dialog="payment-dialog-{{ $payment->id }}">{{ __('app.common.view.actions.close') }}</button>
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
    </script>
@endsection
