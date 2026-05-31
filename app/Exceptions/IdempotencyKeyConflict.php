<?php

namespace App\Exceptions;

class IdempotencyKeyConflict extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'IDEMPOTENCY_KEY_CONFLICT';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Idempotency key was already used with a different request payload.');
    }
}
