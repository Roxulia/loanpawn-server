<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class AccountNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'ACCOUNT_NOT_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionAccountNotFound);
        parent::__construct($message);
    }
}
