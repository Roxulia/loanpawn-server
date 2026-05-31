<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class TenantUserNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'TENANT_USER_NOT_FOUND';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? MessageCodes::$messages['eb025']);
    }
}
