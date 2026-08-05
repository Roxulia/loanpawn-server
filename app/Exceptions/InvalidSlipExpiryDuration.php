<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class InvalidSlipExpiryDuration extends ApiException
{
    protected int $status = 422;
    protected string $errorCode = 'INVALID_SLIP_EXPIRY_DURATION';

    public function __construct()
    {
        parent::__construct(app(Messages::class)->responseMessage(MessageCode::ExceptionInvalidSlipExpiryDuration));
    }
}
