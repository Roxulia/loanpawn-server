<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class InvalidTenantRequest extends ApiException
{
    protected int $status = 422;
    protected string $errorCode = 'INVALID_TENANT_REQUEST';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionInvalidTenantRequest));
    }
}
