<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\DataObjects\RequestObjects\TenantRequestPaymentSubmit;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformBillingService;
use App\Services\PlatformModule\TenantRequestService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingManagementController extends Controller
{
    public function __construct(
        private PlatformBillingService $billingService,
        private TenantRequestService $tenantRequestService,
    ) {
    }

    public function index(Request $request): View
    {
        return view('platform.billing.index', [
            'billing' => $this->billingService->getBillingOverview(),
            'openPaymentTenantRequestId' => (int) $request->query('open_request', session('open_payment_tenant_request_id', 0)),
        ]);
    }

    public function submitPayment(Request $request, int $tenantRequest): RedirectResponse
    {
        $validated = $request->validate([
            'payment_screenshot' => ['required', 'image', 'max:4096'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'update_key' => ['required', 'integer', 'min:0'],
        ], [], __('validation.attributes'));

        try {
            $this->tenantRequestService->submitPaymentScreenshot(new TenantRequestPaymentSubmit(
                tenantRequestId: $tenantRequest,
                paymentScreenshot: $validated['payment_screenshot'],
                paymentReference: $validated['payment_reference'] ?? null,
                note: $validated['note'] ?? null,
                updateKey: $validated['update_key']
            ));
        } catch (ApiException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('platform.billing.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPlanChangeRequestPaymentSubmitted));
    }
}
