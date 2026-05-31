<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;
use Exception;
use Throwable;

class TenantNotFound extends Exception
{
    protected int $status = 404;
    protected string $errorCode = 'TENANT_NOT_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb001'];
        parent::__construct($message);
    }

}
