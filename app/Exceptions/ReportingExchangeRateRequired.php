<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class ReportingExchangeRateRequired extends ApiException
{
    protected int $status = 422;
    protected string $errorCode = 'REPORTING_EXCHANGE_RATE_REQUIRED';

    public function __construct()
    {
        parent::__construct(app(Messages::class)->responseMessage(MessageCode::FinanceReportingExchangeRateRequired));
    }
}
