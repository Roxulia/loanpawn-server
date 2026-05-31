<?php

namespace App\Mail;

use App\Models\PlatformModule\ManualPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestReviewedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ManualPaymentRequest $paymentRequest,
        public readonly string $decision,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->decision === 'approved'
                ? 'Your LonePawn payment request was accepted'
                : 'Your LonePawn payment request was rejected',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform.payment-request-reviewed',
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
