<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class FinancialAccountAccessDenied extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'FINANCIAL_ACCOUNT_ACCESS_DENIED';

    public function __construct()
    {
        parent::__construct(app(Messages::class)->responseMessage(MessageCode::FinanceAccountNotAssigned));
    }
}
