<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class DuplicateValueFound extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'DUPLICATE_VALUE_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb005'];
        parent::__construct($message);
    }
}
