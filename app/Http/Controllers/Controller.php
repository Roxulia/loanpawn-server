<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $statusCode = 200,
    ): JsonResponse {
        return ApiResponse::successResponse($data, $message, $statusCode);
    }

    protected function errorResponse(
        ?string $message = null,
        mixed $data = null,
        int $statusCode = 400,
    ): JsonResponse {
        return ApiResponse::errorResponse($message, $data, $statusCode);
    }

    protected function validationErrorResponse(mixed $errors): JsonResponse
    {
        return ApiResponse::errorResponse(
            message: ApiResponse::VALIDATION_ERROR_MESSAGE,
            data: ['errors' => $errors],
            statusCode: 422,
        );
    }
}
