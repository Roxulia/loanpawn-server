<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class InvalidUploadFile extends ApiException
{
    protected int $status = 422;
    protected string $errorCode = 'INVALID_UPLOAD_FILE';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionInvalidUploadFile));
    }
}
