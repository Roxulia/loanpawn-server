<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class TenantUserNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'TENANT_USER_NOT_FOUND';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionTenantUserNotFound));
    }
}
