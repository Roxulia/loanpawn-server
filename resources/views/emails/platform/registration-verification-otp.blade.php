<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">{{ __('app.auth.view.verify_platform_account') }}</h2>
    <p>{{ __('app.common.view.greeting', ['name' => $recipientName ?? __('app.common.view.there')]) }}</p>
    <p>{{ __('app.auth.view.registration_otp_body', ['minutes' => $expiresInMinutes]) }}</p>
    <div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#eef2ff;border-radius:8px;padding:16px;text-align:center;">
        {{ $otp }}
    </div>
    <p style="color:#667085;font-size:13px;margin-bottom:0;">{{ __('app.auth.view.registration_ignore') }}</p>
</div>
</body>
</html>
