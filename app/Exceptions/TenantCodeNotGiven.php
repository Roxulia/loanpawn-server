<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class TenantCodeNotGiven extends ApiException
{
    protected int $status = 400;
    protected string $errorCode = 'TENANT_CODE_NOT_GIVEN';
    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionTenantCodeNotGiven);
        parent::__construct($message);
    }
}
