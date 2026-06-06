<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class IdempotencyKeyConflict extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'IDEMPOTENCY_KEY_CONFLICT';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionIdempotencyKeyConflict));
    }
}
