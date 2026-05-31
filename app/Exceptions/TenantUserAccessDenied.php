<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class TenantUserAccessDenied extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'TENANT_USER_ACCESS_DENIED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? MessageCodes::$messages['eb024']);
    }
}
