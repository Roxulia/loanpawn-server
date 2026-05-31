<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\AdminBillingService;
use App\Services\PlatformModule\TenantRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminPaymentRequestController extends Controller
{
    public function __construct(
        private AdminBillingService $billingService,
        private TenantRequestService $tenantRequestService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.payment-requests.index', [
            'payments' => $this->billingService->pendingPaymentRequests(),
        ]);
    }

    public function show(int $paymentRequest): View
    {
        return view('platform.admin.payment-requests.show', [
            'payment' => $this->billingService->findPaymentRequest($paymentRequest),
        ]);
    }

    public function accept(Request $request, int $paymentRequest): RedirectResponse
    {
        $payment = $this->billingService->findPaymentRequest($paymentRequest);
        $validated = $request->validate([
            'admin_review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->tenantRequestService->acceptRequest(
            (int) $payment->tenant_request_id,
            $validated['admin_review_note'] ?? null
        );

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('status', 'Payment request accepted and tenant license updated.');
    }

    public function reject(Request $request, int $paymentRequest): RedirectResponse
    {
        $payment = $this->billingService->findPaymentRequest($paymentRequest);
        $validated = $request->validate([
            'admin_review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->tenantRequestService->declineRequest(
            (int) $payment->tenant_request_id,
            $validated['admin_review_note'] ?? null
        );

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('status', 'Payment request rejected.');
    }
}
