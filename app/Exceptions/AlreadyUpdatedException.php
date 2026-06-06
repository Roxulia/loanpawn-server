<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class AlreadyUpdatedException extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'UPDATING_ALREADY_UPDATED_DATA';

    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionAlreadyUpdated);
        parent::__construct($message);
    }
}
