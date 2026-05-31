<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformDashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private PlatformDashboardService $dashboardService,
    ) {
    }

    public function index(): View
    {
        return view('platform.dashboard', [
            'summary' => $this->dashboardService->getSummary(),
        ]);
    }
}
