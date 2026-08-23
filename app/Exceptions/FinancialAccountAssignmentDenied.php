<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class FinancialAccountAssignmentDenied extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'FINANCIAL_ACCOUNT_ASSIGNMENT_DENIED';

    public function __construct(MessageCode $messageCode)
    {
        parent::__construct(app(Messages::class)->responseMessage($messageCode));
    }
}
