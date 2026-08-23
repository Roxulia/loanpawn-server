<?php

namespace Tests\Unit;

use App\Events\ExchangeRateChanged;
use App\Jobs\CheckExpirePawnLoanContractSlipJob;
use App\Jobs\CheckExpireTenantLicenseJob;
use App\Jobs\ExpireInactiveTenantUsersJob;
use App\Jobs\PurgeExpiredTenantUserNotificationsJob;
use App\Jobs\ResetTenantLicenseMonthlySlipCountJob;
use App\Jobs\Telegram\SendInternalServerErrorTelegramNotificationJob;
use App\Listeners\RebuildDailyExchangeRateSummary;
use App\Mail\PaymentRequestReviewedMail;
use App\Mail\PlatformPasswordResetOtpMail;
use App\Mail\PlatformRegistrationVerificationMail;
use App\Mail\TenantLicenseExpiringMail;
use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\TenantLicense;
use App\Services\ExchangeRate\ExchangeRateSummaryService;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        $this->assertSame('scheduled', (new ExpireInactiveTenantUsersJob)->queue);
        $this->assertSame('scheduled', (new PurgeExpiredTenantUserNotificationsJob)->queue);
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
        $this->assertSame('redis', (new ExpireInactiveTenantUsersJob)->connection);
        $this->assertSame('redis', (new PurgeExpiredTenantUserNotificationsJob)->connection);
    }

    public function test_tenant_user_expiration_job_prevents_overlapping_executions(): void
    {
        $middleware = (new ExpireInactiveTenantUsersJob)->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_exchange_rate_summary_listener_is_queued_after_commit_and_prevents_overlap(): void
    {
        $event = new ExchangeRateChanged('tenant:42', 42, 7, '2026-08-17');
        $summaries = Mockery::mock(ExchangeRateSummaryService::class);
        $summaries->shouldReceive('rebuild')->once()->with('tenant:42', 42, 7, '2026-08-17');
        $listener = new RebuildDailyExchangeRateSummary($summaries);
        $middleware = $listener->middleware($event);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame([10, 60, 300], $listener->backoff());
        $listener->handle($event);
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
