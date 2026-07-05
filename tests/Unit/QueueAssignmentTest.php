<?php

namespace Tests\Unit;

use App\Jobs\CheckExpirePawnLoanContractSlipJob;
use App\Jobs\CheckExpireTenantLicenseJob;
use App\Jobs\ResetTenantLicenseMonthlySlipCountJob;
use App\Jobs\Telegram\SendInternalServerErrorTelegramNotificationJob;
use App\Mail\PaymentRequestReviewedMail;
use App\Mail\PlatformPasswordResetOtpMail;
use App\Mail\PlatformRegistrationVerificationMail;
use App\Mail\TenantLicenseExpiringMail;
use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\TenantLicense;
use App\Support\RedisAvailability;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class QueueAssignmentTest extends TestCase
{
    public function test_scheduled_job_uses_scheduled_queue(): void
    {
        $this->assertSame('scheduled', (new CheckExpireTenantLicenseJob)->queue);
        $this->assertSame('scheduled', (new CheckExpirePawnLoanContractSlipJob)->queue);
        $this->assertSame('scheduled', (new ResetTenantLicenseMonthlySlipCountJob)->queue);
    }

    public function test_scheduled_jobs_use_selected_queue_connection(): void
    {
        $this->app->forgetInstance(RedisAvailability::class);

        $connection = Mockery::mock();
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn('PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->assertSame('redis', (new CheckExpireTenantLicenseJob)->connection);
        $this->assertSame('redis', (new CheckExpirePawnLoanContractSlipJob)->connection);
        $this->assertSame('redis', (new ResetTenantLicenseMonthlySlipCountJob)->connection);
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

    public function test_internal_server_error_telegram_job_uses_telegram_queue(): void
    {
        config(['services.telegram.queue' => 'telegram']);

        $job = new SendInternalServerErrorTelegramNotificationJob([]);

        $this->assertSame('telegram', $job->queue);
    }
}
