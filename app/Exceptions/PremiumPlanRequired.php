<?php

namespace App\Exceptions;

class PremiumPlanRequired extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'PREMIUM_PLAN_REQUIRED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Current tenant must have a premium license.');
    }
}
