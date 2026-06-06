<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class DuplicateValueFound extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'DUPLICATE_VALUE_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionDuplicateValueFound);
        parent::__construct($message);
    }
}
