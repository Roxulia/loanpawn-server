<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class EmailNotRegistered extends ApiException
{
    protected int $status = 404;

    protected string $errorCode = 'EMAIL_NOT_REGISTERED';

    public function __construct(?string $message = null)
    {
        $message ??= app(Messages::class)->responseMessage(MessageCode::ExceptionEmailNotRegistered);

        parent::__construct($message);
    }
}
