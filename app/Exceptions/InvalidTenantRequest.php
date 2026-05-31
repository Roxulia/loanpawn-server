<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class InvalidTenantRequest extends ApiException
{
    protected int $status = 422;
    protected string $errorCode = 'INVALID_TENANT_REQUEST';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? MessageCodes::$messages['eb016']);
    }
}
