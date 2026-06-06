<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class FeatureNotAvailableForPlan extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'FEATURE_NOT_AVAILABLE_FOR_PLAN';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionFeatureNotAvailableForPlan));
    }
}
