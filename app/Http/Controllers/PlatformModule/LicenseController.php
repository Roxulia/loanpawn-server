<?php

namespace App\Http\Controllers\PlatformModule;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
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
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->tenantLicenseService->validateLicenseKey($validator->validated()['license_key']);

        return response()->json([
            'data' => $result->toArray(),
        ], $result->valid ? 200 : 422);
    }
}
