<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
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
            ->json($this->standardizedBody(), $this->responseCode)
            ->header('Idempotent-Replay', 'true');
    }

    private function standardizedBody(): array
    {
        if (ApiResponse::isStandardPayload($this->responseBody)) {
            return $this->responseBody;
        }

        $success = $this->responseCode >= 200 && $this->responseCode < 400;
        $message = $this->responseBody['message'] ?? (
            $success ? ApiResponse::DEFAULT_SUCCESS_MESSAGE : ApiResponse::DEFAULT_ERROR_MESSAGE
        );
        $data = $this->responseBody['data'] ?? $this->responseBody;

        if (is_array($data)) {
            unset($data['message'], $data['success']);
        }

        return ApiResponse::payload($success, $message, $data, $this->responseCode);
    }
}
