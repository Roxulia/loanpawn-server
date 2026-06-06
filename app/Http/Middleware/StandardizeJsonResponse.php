<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StandardizeJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (ApiResponse::isStandardPayload($payload)) {
            return $response;
        }

        $statusCode = $response->getStatusCode();
        $success = $this->resolveSuccess($payload, $statusCode);
        $message = $this->resolveMessage($payload, $success);
        $data = $this->resolveData($payload, $success);

        $response->setData(ApiResponse::payload($success, $message, $data, $statusCode));

        return $response;
    }

    private function resolveSuccess(mixed $payload, int $statusCode): bool
    {
        if (is_array($payload) && array_key_exists('success', $payload)) {
            return (bool) $payload['success'];
        }

        return $statusCode >= 200 && $statusCode < 400;
    }

    private function resolveMessage(mixed $payload, bool $success): string
    {
        if (is_array($payload) && is_string($payload['message'] ?? null) && $payload['message'] !== '') {
            return $payload['message'];
        }

        return app(Messages::class)->responseMessage(
            $success ? MessageCode::ApiResponseSuccess : MessageCode::ApiResponseFailed
        );
    }

    private function resolveData(mixed $payload, bool $success): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        if (array_key_exists('data', $payload)) {
            $data = $payload['data'];
        } else {
            $data = $payload;
            unset($data['message'], $data['success']);
        }

        foreach (['errors', 'error', 'code', 'redirect', 'retry_after'] as $extraKey) {
            if (array_key_exists($extraKey, $payload)) {
                if (! is_array($data)) {
                    $data = ['value' => $data];
                }

                $data[$extraKey] = $payload[$extraKey];
            }
        }

        return $data === [] && ! $success ? null : $data;
    }
}
