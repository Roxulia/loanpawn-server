<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\TenantRepository;
use App\Repository\PlatformUserRepository;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct(
        private TenantRepository $tenantRepository,
        private PlatformUserRepository $platformUserRepository,
        private ManualPaymentRequestRepository $paymentRequestRepository,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.dashboard', [
            'summary' => [
                'tenant_count' => $this->tenantRepository->paginateAll(1)->total(),
                'platform_user_count' => $this->platformUserRepository->paginateAll(1)->total(),
                'pending_payment_count' => $this->paymentRequestRepository->countPendingApproval(),
                'approved_total' => $this->paymentRequestRepository->totalApprovedAmount(),
            ],
        ]);
    }
}
