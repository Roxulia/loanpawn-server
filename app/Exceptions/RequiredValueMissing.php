<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class RequiredValueMissing extends ApiException
{
    protected int $status = 400;
    protected string $errorCode = 'REQUIRED_VALUE_MISSING';

    public function __construct(?string $message)
    {
        $message = $message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionRequiredValueMissing);
        parent::__construct($message);
    }
}
