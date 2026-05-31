<?php

namespace App\Exceptions;

use Exception;

class UserNotLoggedIn extends ApiException
{
    protected int $status = 401;
    protected string $errorCode = 'NOT_LOGGED_IN_USER';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'No Logged In Account or User Found');
    }
}
