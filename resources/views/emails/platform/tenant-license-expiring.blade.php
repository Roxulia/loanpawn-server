<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">{{ __('app.platform.view.license_expiring_soon') }}</h2>
    <p>{{ __('app.common.view.greeting', ['name' => $license->tenant?->owner?->name ?? __('app.common.view.there')]) }}</p>
    <p>{{ __('app.platform.view.tenant_license_expiring_body', ['days' => 7]) }}</p>
    <ul>
        <li><strong>{{ __('app.common.view.labels.tenant') }}:</strong> {{ $license->tenant?->name }} ({{ $license->tenant?->tenant_code }})</li>
        <li><strong>{{ __('app.common.view.labels.plan') }}:</strong> {{ $license->plan_type }}</li>
        <li><strong>{{ __('app.platform.view.expiry') }}:</strong> {{ $license->expires_at?->toDayDateTimeString() }}</li>
    </ul>
    <p><a href="{{ $billingUrl }}">{{ __('app.platform.view.open_billing') }}</a> {{ __('app.platform.view.request_extension_before_expiry') }}</p>
</div>
</body>
</html>
