<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Utility\MessageCode;
use App\Utility\Messages;

abstract class Controller
{
    protected function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $statusCode = 200,
    ): JsonResponse {
        return ApiResponse::successResponse(
            $data,
            $message ?? $this->responseMessage(MessageCode::ApiResponseSuccess),
            $statusCode
        );
    }

    protected function errorResponse(
        ?string $message = null,
        mixed $data = null,
        int $statusCode = 400,
    ): JsonResponse {
        return ApiResponse::errorResponse(
            $message ?? $this->responseMessage(MessageCode::ApiResponseFailed),
            $data,
            $statusCode
        );
    }

    protected function validationErrorResponse(mixed $errors): JsonResponse
    {
        return ApiResponse::errorResponse(
            message: $this->responseMessage(MessageCode::ApiValidationFailed),
            data: ['errors' => $errors],
            statusCode: 422,
        );
    }

    protected function responseMessage(MessageCode $code, array $params = []): string
    {
        return app(Messages::class)->responseMessage($code, $params);
    }
}
