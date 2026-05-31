<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">License expiring soon</h2>
    <p>Hello {{ $license->tenant?->owner?->name ?? 'there' }},</p>
    <p>Your LonePawn tenant license will expire in 7 days.</p>
    <ul>
        <li><strong>Tenant:</strong> {{ $license->tenant?->name }} ({{ $license->tenant?->tenant_code }})</li>
        <li><strong>Plan:</strong> {{ $license->plan_type }}</li>
        <li><strong>Expiry:</strong> {{ $license->expires_at?->toDayDateTimeString() }}</li>
    </ul>
    <p><a href="{{ $billingUrl }}">Open billing</a> to request an extension before the license expires.</p>
</div>
</body>
</html>
