<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
    <h2 style="margin-top:0;">Verify your platform account</h2>
    <p>Hello {{ $recipientName ?? 'there' }},</p>
    <p>Use this verification code to activate your LonePawn platform account. The code is valid for {{ $expiresInMinutes }} minutes.</p>
    <div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#eef2ff;border-radius:8px;padding:16px;text-align:center;">
        {{ $otp }}
    </div>
    <p style="color:#667085;font-size:13px;margin-bottom:0;">If you did not create this account, you can ignore this email.</p>
</div>
</body>
</html>
