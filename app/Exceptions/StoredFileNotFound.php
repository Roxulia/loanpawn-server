<?php

namespace App\Exceptions;

use App\Utility\MessageCode;
use App\Utility\Messages;

class StoredFileNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'STORED_FILE_NOT_FOUND';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? app(Messages::class)->responseMessage(MessageCode::ExceptionStoredFileNotFound));
    }
}
