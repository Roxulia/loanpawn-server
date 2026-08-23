<?php

namespace App\Exceptions;

use App\DataObjects\ResponseObjects\AppCompatibilityResource;
use App\Http\Responses\ApiResponse;
use App\Utility\MessageCode;
use App\Utility\Messages;

class UnsupportedFrontendVersion extends ApiException
{
    protected int $status = 426;

    protected string $errorCode = 'UNSUPPORTED_FRONTEND_VERSION';

    public function __construct(private readonly AppCompatibilityResource $compatibility)
    {
        parent::__construct(app(Messages::class)->responseMessage(MessageCode::AppFrontendUpdateRequired));
    }

    public function render($request)
    {
        return ApiResponse::errorResponse(
            message: $this->getMessage(),
            data: ['code' => $this->errorCode, ...$this->compatibility->toArray()],
            statusCode: $this->status,
        );
    }
}
