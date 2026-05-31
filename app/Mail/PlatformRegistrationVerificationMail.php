<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformRegistrationVerificationMail extends Mailable
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
            subject: 'Verify your LonePawn platform account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform.registration-verification-otp',
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
