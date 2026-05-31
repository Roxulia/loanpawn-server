<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class RequiredValueMissing extends ApiException
{
    protected int $status = 400;
    protected string $errorCode = 'REQUIRED_VALUE_MISSING';

    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb006'];
        parent::__construct($message);
    }
}
