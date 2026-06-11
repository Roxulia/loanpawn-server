<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class TenantNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'TENANT_NOT_FOUND';

    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionTenantNotFound);
        parent::__construct($message);
    }

}
