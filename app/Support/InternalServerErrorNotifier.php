<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Jobs\Telegram\SendInternalServerErrorTelegramNotificationJob;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class InternalServerErrorNotifier
{
    public function notify(Throwable $exception, Request $request): void
    {
        if (! $this->shouldNotify($exception)) {
            return;
        }

        try {
            dispatch(new SendInternalServerErrorTelegramNotificationJob(
                $this->context($exception, $request)
            ));
        } catch (Throwable $notificationException) {
            Log::warning('Internal server error Telegram notification dispatch failed.', [
                'exception' => $notificationException::class,
                'message' => $notificationException->getMessage(),
            ]);
        }
    }

    public function shouldNotify(Throwable $exception): bool
    {
        if (
            $exception instanceof ApiException
            || $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
        ) {
            return false;
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode() === 500;
        }

        return true;
    }

    private function context(Throwable $exception, Request $request): array
    {
        $tenant = $request->attributes->get('tenant');
        $tenantContext = app(TenantContext::class);
        $user = $request->user();

        return [
            'environment' => app()->environment(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_id' => $user?->getAuthIdentifier(),
            'tenant_id' => $tenant?->id ?? $tenantContext->id(),
            'tenant_name' => $tenant?->name ?? $tenantContext->name(),
            'occurred_at' => now()->toDateTimeString(),
            'trace' => $this->tracePreview($exception),
        ];
    }

    private function tracePreview(Throwable $exception): string
    {
        return collect(explode("\n", $exception->getTraceAsString()))
            ->take(5)
            ->implode("\n");
    }
}
