<?php

namespace Tests\Feature;

use App\Jobs\Telegram\SendInternalServerErrorTelegramNotificationJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class InternalServerErrorTelegramNotificationTest extends TestCase
{
    public function test_unhandled_exception_dispatches_internal_server_error_telegram_job(): void
    {
        Queue::fake();

        Route::get('/test/internal-server-error-notification', function () {
            throw new RuntimeException('Unexpected failure.');
        });

        $this->get('/test/internal-server-error-notification')
            ->assertStatus(500);

        Queue::assertPushed(SendInternalServerErrorTelegramNotificationJob::class, function (SendInternalServerErrorTelegramNotificationJob $job): bool {
            $context = $job->context();

            return $context['exception'] === RuntimeException::class
                && $context['message'] === 'Unexpected failure.'
                && $context['method'] === 'GET'
                && $context['path'] === 'test/internal-server-error-notification';
        });
    }

    public function test_non_500_http_exception_does_not_dispatch_internal_server_error_telegram_job(): void
    {
        Queue::fake();

        Route::get('/test/not-found-notification', function () {
            throw new NotFoundHttpException('Not found.');
        });

        $this->get('/test/not-found-notification')
            ->assertStatus(404);

        Queue::assertNotPushed(SendInternalServerErrorTelegramNotificationJob::class);
    }
}
