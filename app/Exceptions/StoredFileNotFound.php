<?php

namespace App\Exceptions;

use App\Utility\MessageCodes;

class StoredFileNotFound extends ApiException
{
    protected int $status = 404;
    protected string $errorCode = 'STORED_FILE_NOT_FOUND';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? MessageCodes::$messages['eb015']);
    }
}
