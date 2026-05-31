<?php

namespace App\Exceptions;

class FeatureNotAvailableForPlan extends ApiException
{
    protected int $status = 403;
    protected string $errorCode = 'FEATURE_NOT_AVAILABLE_FOR_PLAN';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Current tenant plan does not allow this feature.');
    }
}
