<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use App\Utility\MessageCode;
use App\Utility\Messages;

class LoginRetryLocked extends ApiException
{
    protected int $status = 429;

    protected string $errorCode = 'LOGIN_RETRY_LOCKED';

    public function __construct(private int $retryAfterSeconds)
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));
        $message = app(Messages::class)->responseMessage(MessageCode::ExceptionLoginRetryLocked, [
            'minutes' => $minutes,
        ]);

        parent::__construct($message);
    }

    public function render($request)
    {
        return ApiResponse::errorResponse(
            message: $this->getMessage(),
            data: [
                'code' => $this->errorCode,
                'retry_after' => $this->retryAfterSeconds,
            ],
            statusCode: $this->status,
        );
    }
}
