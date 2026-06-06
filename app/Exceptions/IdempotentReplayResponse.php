<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Exception;

class IdempotentReplayResponse extends Exception
{
    public function __construct(
        private array $responseBody,
        private int $responseCode,
    ) {
        parent::__construct(app(Messages::class)->responseMessage(MessageCode::ExceptionIdempotentReplay));
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
            app(Messages::class)->responseMessage($success ? MessageCode::ApiResponseSuccess : MessageCode::ApiResponseFailed)
        );
        $data = $this->responseBody['data'] ?? $this->responseBody;

        if (is_array($data)) {
            unset($data['message'], $data['success']);
        }

        return ApiResponse::payload($success, $message, $data, $this->responseCode);
    }
}
