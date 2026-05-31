<?php

namespace App\Exceptions;

use Exception;
use App\Utility\MessageCodes;

class TenantCodeNotGiven extends ApiException
{
    protected int $status = 400;
    protected string $errorCode = 'TENANT_CODE_NOT_GIVEN';
    public function __construct(?string $message)
    {
        $message = $message ?? MessageCodes::$messages['eb002'];
        parent::__construct($message);
    }
}
