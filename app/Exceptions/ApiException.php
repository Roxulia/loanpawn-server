<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;

abstract class ApiException extends Exception
{
    protected int $status = 400;
    protected string $errorCode = 'UNKNOWN_ERROR';

    public function statusCode(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render($request)
    {
        return ApiResponse::errorResponse(
            message: $this->getMessage(),
            data: ['code' => $this->errorCode],
            statusCode: $this->status,
        );
    }
}
