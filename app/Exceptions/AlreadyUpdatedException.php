<?php

namespace App\Exceptions;

use Exception;
use App\Utility\MessageCodes;

class AlreadyUpdatedException extends ApiException
{
    protected int $status = 409;
    protected string $errorCode = 'UPDATING_ALREADY_UPDATED_DATA';

    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb004'];
        parent::__construct($message);
    }
}
