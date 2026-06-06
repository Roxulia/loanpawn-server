<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use JsonSerializable;
use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    public const DEFAULT_SUCCESS_MESSAGE = 'Request completed successfully.';
    public const DEFAULT_ERROR_MESSAGE = 'Request failed.';
    public const VALIDATION_ERROR_MESSAGE = 'Validation failed.';

    public static function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $statusCode = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json(
            self::payload(
                success: true,
                message: $message ?? self::DEFAULT_SUCCESS_MESSAGE,
                data: $data,
                statusCode: $statusCode,
            ),
            $statusCode,
        );
    }

    public static function errorResponse(
        ?string $message = null,
        mixed $data = null,
        int $statusCode = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json(
            self::payload(
                success: false,
                message: $message ?? self::DEFAULT_ERROR_MESSAGE,
                data: $data,
                statusCode: $statusCode,
            ),
            $statusCode,
        );
    }

    public static function payload(bool $success, string $message, mixed $data, int $statusCode): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data' => self::normalizeData($data),
            'statusCode' => $statusCode,
        ];
    }

    public static function normalizeData(mixed $data): mixed
    {
        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        if ($data instanceof Collection) {
            return $data->toArray();
        }

        if ($data instanceof JsonSerializable) {
            return $data->jsonSerialize();
        }

        return $data;
    }

    public static function isStandardPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach (['success', 'message', 'data', 'statusCode'] as $key) {
            if (! array_key_exists($key, $payload)) {
                return false;
            }
        }

        return true;
    }
}
