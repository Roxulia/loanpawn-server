@extends('platform.layouts.app')

@section('title', 'Billing Management')
@section('pageTitle', 'Billing Management')
@section('pageDescription', 'Review bill payment history, pending manual payment requests, and approved payment totals.')

@section('content')
    <section class="grid kpi">
        <div class="panel">
            <p class="metric-label">Pending Requests</p>
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
                    <p>Payment history and pending requests will appear here after tenant upgrade or extension requests are submitted.</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>Tenant</th>
                        <th>Request</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Payment Status</th>
                        <th>Request Status</th>
                        <th>Reviewed</th>
                        <th>Attachment</th>
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
                                    <button type="button" class="button secondary" data-open-dialog="payment-dialog-{{ $payment->id }}">Submit Attachment</button>
                                @else
                                    {{ $payment->attachments->count() }} file{{ $payment->attachments->count() === 1 ? '' : 's' }}
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
                        <h2>Submit Payment Attachment</h2>
                        <button type="button" class="dialog-close" data-close-dialog="payment-dialog-{{ $payment->id }}">Close</button>
                    </div>
                    <div class="grid">
                        <div>
                            <label>Tenant</label>
                            <input value="{{ $payment->tenant?->name ?? '-' }}" disabled>
                        </div>
                        <div>
                            <label>Amount</label>
                            <input value="{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}" disabled>
                        </div>
                        <div>
                            <label for="payment_reference_{{ $payment->id }}">Payment Reference</label>
                            <input id="payment_reference_{{ $payment->id }}" name="payment_reference" value="{{ old('payment_reference') }}">
                        </div>
                        <div>
                            <label for="payment_screenshot_{{ $payment->id }}">Payment Attachment</label>
                            <input id="payment_screenshot_{{ $payment->id }}" type="file" name="payment_screenshot" accept="image/*" required>
                            @error('payment_screenshot') <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label for="payment_note_{{ $payment->id }}">Note</label>
                            <textarea id="payment_note_{{ $payment->id }}" name="note">{{ old('note') }}</textarea>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <button type="submit" class="button primary">Submit Payment</button>
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
