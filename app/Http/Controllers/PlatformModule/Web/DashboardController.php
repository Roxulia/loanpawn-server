<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private PlatformDashboardService $dashboardService,
    ) {
    }

    public function index(Request $request): View
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

        return view('platform.dashboard', [
            'summary' => $this->dashboardService->getSummary(DashboardTimeFilter::fromValidated($validated)),
        ]);
    }
}
