<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class InvalidCredential extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'INVALID_CREDENTIAL';
    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionInvalidCredential);
        parent::__construct($message);
    }
}
