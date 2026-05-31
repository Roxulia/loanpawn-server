<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\AdminBillingService;
use Illuminate\View\View;

class AdminBillingManagementController extends Controller
{
    public function __construct(
        private AdminBillingService $billingService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.billing.index', [
            'billing' => $this->billingService->getBillingOverview(),
        ]);
    }
}
