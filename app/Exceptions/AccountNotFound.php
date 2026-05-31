<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;
use Exception;

class AccountNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'ACCOUNT_NOT_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb004'];
        parent::__construct($message);
    }
}
