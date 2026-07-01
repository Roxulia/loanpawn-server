<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PaymentQrService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPaymentQrController extends Controller
{
    public function __construct(
        private PaymentQrService $paymentQrService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.payment-qrs.index', [
            'qrImages' => $this->paymentQrService->paginateForAdmin(),
            'activeQr' => $this->paymentQrService->active(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'qr_image' => ['required', 'image', 'max:4096'],
        ], [], __('validation.attributes'));

        $this->paymentQrService->upload($validated['qr_image']);

        return redirect()
            ->route('admin.payment-qrs.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPaymentQrUploaded));
    }

    public function activate(int $paymentQr): RedirectResponse
    {
        $this->paymentQrService->activate($paymentQr);

        return redirect()
            ->route('admin.payment-qrs.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPaymentQrActivated));
    }

    public function image(int $paymentQr): StreamedResponse
    {
        return $this->paymentQrService->streamImage($paymentQr);
    }
}
