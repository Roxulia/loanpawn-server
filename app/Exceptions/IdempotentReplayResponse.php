<?php

namespace App\Exceptions;

use Exception;

class IdempotentReplayResponse extends Exception
{
    public function __construct(
        private array $responseBody,
        private int $responseCode,
    ) {
        parent::__construct('Idempotent request replay.');
    }

    public function render($request)
    {
        return response()
            ->json($this->responseBody, $this->responseCode)
            ->header('Idempotent-Replay', 'true');
    }
}
