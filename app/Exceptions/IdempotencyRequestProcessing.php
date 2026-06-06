<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class IdempotencyRequestProcessing extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'IDEMPOTENCY_REQUEST_PROCESSING';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionIdempotencyRequestProcessing));
    }
}
