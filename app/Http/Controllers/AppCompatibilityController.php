<?php

namespace App\Http\Controllers;

use App\DataObjects\RequestObjects\AppVersionRequest;
use App\Services\AppCompatibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppCompatibilityController extends Controller
{
    public function __construct(private AppCompatibilityService $compatibilityService) {}

    public function show(Request $request): JsonResponse
    {
        $compatibility = $this->compatibilityService->check(new AppVersionRequest(
            installedVersion: $request->header('X-LonePawn-App-Version'),
        ));

        return $this->successResponse($compatibility->toArray())
            ->header('Cache-Control', 'no-store');
    }
}
