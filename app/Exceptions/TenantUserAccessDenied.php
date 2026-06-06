<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class TenantUserAccessDenied extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'TENANT_USER_ACCESS_DENIED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionTenantUserAccessDenied));
    }
}
