<?php

namespace App\Exceptions;

use Exception;

abstract class ApiException extends Exception
{
    protected int $status = 400;
    protected string $errorCode = 'UNKNOWN_ERROR';

    public function render($request)
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
