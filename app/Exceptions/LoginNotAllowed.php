<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class LoginNotAllowed extends ApiException
{
    protected int $status = 403;

    protected string $errorCode = 'LOGIN_NOT_ALLOWED';

    public function __construct(?string $message = null)
    {
        $message ??= app(Messages::class)->responseMessage(MessageCode::ExceptionLoginNotAllowed);

        parent::__construct($message);
    }
}
