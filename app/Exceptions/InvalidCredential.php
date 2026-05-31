<?php

namespace App\Exceptions;

use Exception;
use App\Utility\MessageCodes;

class InvalidCredential extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'INVALID_CREDENTIAL';
    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb003'];
        parent::__construct($message);
    }
}
