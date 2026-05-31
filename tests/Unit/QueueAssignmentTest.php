<?php

namespace Tests\Unit;

use App\Jobs\CheckExpireTenantLicenseJob;
use App\Mail\PaymentRequestReviewedMail;
use App\Mail\PlatformPasswordResetOtpMail;
use App\Mail\PlatformRegistrationVerificationMail;
use App\Mail\TenantLicenseExpiringMail;
use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\TenantLicense;
use PHPUnit\Framework\TestCase;

class QueueAssignmentTest extends TestCase
{
    public function test_scheduled_job_uses_scheduled_queue(): void
    {
        $this->assertSame('scheduled', (new CheckExpireTenantLicenseJob)->queue);
    }

    public function test_mailables_use_mail_queue(): void
    {
        $mailables = [
            new PlatformPasswordResetOtpMail('123456', 10),
            new PlatformRegistrationVerificationMail('123456', 10),
            new PaymentRequestReviewedMail(new ManualPaymentRequest, 'approved'),
            new TenantLicenseExpiringMail(new TenantLicense, '/billing'),
        ];

        foreach ($mailables as $mailable) {
            $this->assertSame('mail', $mailable->queue);
        }
    }
}
