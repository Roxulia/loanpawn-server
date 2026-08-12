<?php

namespace App\Exceptions;

class AccountingDayClosed extends ApiException
{
    protected int $status = 409;

    protected string $errorCode = 'ACCOUNTING_DAY_CLOSED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Financial data cannot be changed because its accounting day is closed.');
    }
}
