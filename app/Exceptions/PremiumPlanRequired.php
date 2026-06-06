<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class PremiumPlanRequired extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'PREMIUM_PLAN_REQUIRED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionPremiumPlanRequired));
    }
}
