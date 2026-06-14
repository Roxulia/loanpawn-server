<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantDashboardService;
use Illuminate\Http\JsonResponse;

class TenantDashboardController extends Controller
{
    public function __construct(
        private TenantDashboardService $dashboardService,
    ) {
    }

    public function summary(): JsonResponse
    {
        return $this->successResponse($this->dashboardService->summary()->toArray());
    }
}
