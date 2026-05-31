<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformPasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly int $expiresInMinutes,
        public readonly ?string $recipientName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password reset OTP',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform.password-reset-otp',
        );
    }

    /**
     * @return array<int, string>
     */
    public function attachments(): array
    {
        return [];
    }
}
