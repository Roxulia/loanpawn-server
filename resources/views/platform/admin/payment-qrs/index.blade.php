@extends('platform.admin.layouts.app')

@section('title', __('app.billing.view.payment_qr_management'))
@section('pageTitle', __('app.billing.view.payment_qr_management'))
@section('pageDescription', __('app.billing.view.payment_qr_management_description'))

@section('content')
    <div class="admin-stack admin-payment-qr-page">
    <section class="grid two">
        <form class="panel" method="POST" action="{{ route('admin.payment-qrs.store') }}" enctype="multipart/form-data">
            @csrf
            <p class="admin-section-kicker">{{ __('app.billing.view.upload_qr') }}</p>
            <div style="margin-top: 12px;">
                <label for="qr_image">{{ __('app.billing.view.qr_image') }}</label>
                <input id="qr_image" type="file" name="qr_image" accept="image/*" required>
                @error('qr_image') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div style="margin-top: 14px;">
                <button type="submit" class="button primary">{{ __('app.billing.view.upload_qr') }}</button>
            </div>
        </form>

        <section class="panel">
            <p class="admin-section-kicker">{{ __('app.billing.view.active_payment_qr') }}</p>
            @if ($activeQr)
                <div class="payment-qr-active-preview" style="margin-top: 12px;">
                    <img src="{{ route('admin.payment-qrs.image', $activeQr->id) }}" alt="{{ $activeQr->original_name ?? __('app.billing.view.payment_qr') }}">
                    <div>
                        <strong>{{ $activeQr->original_name ?? basename($activeQr->file_path) }}</strong>
                        <p class="muted">{{ __('app.billing.view.active_payment_qr_description') }}</p>
                        <p class="muted">
                            <time datetime="{{ $activeQr->activated_at?->toISOString() }}" data-local-time="datetime">
                                {{ $activeQr->activated_at?->format('Y-m-d H:i') ?? '-' }}
                            </time>
                        </p>
                    </div>
                </div>
            @else
                <div class="empty-state" style="margin-top: 12px;">
                    <div>
                        <h2>{{ __('app.billing.view.no_qr_images') }}</h2>
                        <p class="muted">{{ __('app.billing.view.no_qr_images_description') }}</p>
                    </div>
                </div>
            @endif
        </section>
    </section>

    <section class="panel">
        <div class="admin-section-heading"><div><p class="admin-section-kicker">QR history</p><h2>Payment QR catalog</h2><p>Review uploads and choose the QR currently shown to customers.</p></div></div>
        @if ($qrImages->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>{{ __('app.billing.view.no_qr_images') }}</h2>
                    <p class="muted">{{ __('app.billing.view.no_qr_images_description') }}</p>
                </div>
            </div>
        @else
            <div class="table-wrap admin-table--desktop admin-cards--mobile">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('app.billing.view.payment_qr') }}</th>
                        <th>{{ __('app.common.view.labels.name') }}</th>
                        <th>{{ __('app.common.view.labels.type') }}</th>
                        <th>{{ __('app.common.view.labels.user') }}</th>
                        <th>{{ __('app.common.view.labels.created') }}</th>
                        <th>{{ __('app.common.view.labels.status') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($qrImages as $qrImage)
                        <tr>
                            <td data-label="{{ __('app.billing.view.payment_qr') }}">
                                <img class="payment-qr-thumb" src="{{ route('admin.payment-qrs.image', $qrImage->id) }}" alt="{{ $qrImage->original_name ?? __('app.billing.view.payment_qr') }}">
                            </td>
                            <td data-label="{{ __('app.common.view.labels.name') }}">{{ $qrImage->original_name ?? basename($qrImage->file_path) }}</td>
                            <td data-label="{{ __('app.common.view.labels.type') }}">{{ $qrImage->mime_type ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.user') }}">{{ $qrImage->uploader?->name ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.created') }}">
                                <time datetime="{{ $qrImage->created_at?->toISOString() }}" data-local-time="datetime">
                                    {{ $qrImage->created_at?->format('Y-m-d H:i') ?? '-' }}
                                </time>
                            </td>
                            <td data-label="{{ __('app.common.view.labels.status') }}">
                                <span class="badge">{{ $qrImage->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td data-label="">
                                @if (! $qrImage->is_active)
                                    <form method="POST" action="{{ route('admin.payment-qrs.activate', $qrImage->id) }}">
                                        @csrf
                                        <button type="submit" class="button secondary">{{ __('app.billing.view.activate_qr') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $qrImages->links() }}
            </div>
        @endif
    </section>
    </div>
@endsection
