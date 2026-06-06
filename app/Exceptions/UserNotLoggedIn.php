<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class UserNotLoggedIn extends ApiException
{
    protected int $status = 401;
    protected string $errorCode = 'NOT_LOGGED_IN_USER';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionUserNotLoggedIn));
    }
}
