<?php

namespace App\Exceptions;

class IdempotencyRequestProcessing extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'IDEMPOTENCY_REQUEST_PROCESSING';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Idempotent request is still processing. Please retry shortly.');
    }
}
