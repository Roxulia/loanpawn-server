<?php

namespace App\Exceptions;

class TenantAccessDenied extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'TENANT_ACCESS_DENIED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'You do not have access to this tenant.');
    }
}
