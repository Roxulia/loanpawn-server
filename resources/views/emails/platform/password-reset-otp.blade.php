<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fb;font-family:Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;padding:24px 32px;color:#ffffff;">
                            <div style="font-size:20px;font-weight:700;">LonePawn</div>
                            <div style="font-size:13px;opacity:0.85;margin-top:4px;">Password recovery verification</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px 0;font-size:16px;">
                                Hello{{ $recipientName ? ' '.$recipientName : '' }},
                            </p>
                            <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;">
                                Use the OTP below to reset your password. This code is valid for {{ $expiresInMinutes }} minutes.
                            </p>
                            <div style="margin:0 0 24px 0;padding:18px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;text-align:center;">
                                <span style="display:inline-block;font-size:32px;letter-spacing:10px;font-weight:700;color:#1d4ed8;">{{ $otp }}</span>
                            </div>
                            <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#4b5563;">
                                If you did not request this password reset, you can ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;">
                            This is an automated security email from LonePawn.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
