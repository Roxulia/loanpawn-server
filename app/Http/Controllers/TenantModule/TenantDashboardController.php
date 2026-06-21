<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantDashboardController extends Controller
{
    public function __construct(
        private TenantDashboardService $dashboardService,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'time_filter' => ['nullable', Rule::in([
                DashboardTimeFilter::THIS_DAY,
                DashboardTimeFilter::THIS_WEEK,
                DashboardTimeFilter::THIS_MONTH,
                DashboardTimeFilter::CUSTOM,
            ])],
            'start_date' => ['required_if:time_filter,'.DashboardTimeFilter::CUSTOM, 'date'],
            'end_date' => ['required_if:time_filter,'.DashboardTimeFilter::CUSTOM, 'date', 'after_or_equal:start_date'],
        ]);

        return $this->successResponse(
            $this->dashboardService->summary(DashboardTimeFilter::fromValidated($validated))->toArray()
        );
    }
}
