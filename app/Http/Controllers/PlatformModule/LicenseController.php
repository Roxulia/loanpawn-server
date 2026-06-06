<?php

namespace App\Http\Controllers\PlatformModule;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LicenseController extends Controller
{
    public function __construct(
        private TenantLicenseService $tenantLicenseService,
    ) {
    }

    public function validateLicense(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'license_key' => ['required', 'string', 'size:16'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $result = $this->tenantLicenseService->validateLicenseKey($validator->validated()['license_key']);

        if (! $result->valid) {
            return $this->errorResponse($this->responseMessage(MessageCode::PlatformLicenseValidationFailed), $result->toArray(), 422);
        }

        return $this->successResponse($result->toArray());
    }
}
