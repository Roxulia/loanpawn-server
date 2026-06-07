<?php

namespace App\Mail;

use App\Models\PlatformModule\ManualPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRequestReviewedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ManualPaymentRequest $paymentRequest,
        public readonly string $decision,
    ) {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('app.billing.view.payment_request_decision', [
                'decision' => $this->decisionLabel(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform.payment-request-reviewed',
            with: [
                'decisionLabel' => $this->decisionLabel(),
            ],
        );
    }

    protected function decisionLabel(): string
    {
        return __('app.billing.view.payment_request_status.'.$this->decision);
    }

    /**
     * @return array<int, string>
     */
    public function attachments(): array
    {
        return [];
    }
}
