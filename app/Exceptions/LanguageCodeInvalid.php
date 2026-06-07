<?php

namespace App\Exceptions;

use App\Utility\Messages;
use App\Utility\MessageCode;
use Exception;

class LanguageCodeInvalid extends ApiException
{
    protected $code = 422;
    protected $message;

    public function __construct()
    {
        $this->message = app(Messages::class)->responseMessage(MessageCode::LanguageCodeInvalid);
        parent::__construct($this->message, $this->code);
    }
}
