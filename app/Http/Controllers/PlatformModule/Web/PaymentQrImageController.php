<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PaymentQrService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentQrImageController extends Controller
{
    public function __construct(
        private PaymentQrService $paymentQrService,
    ) {
    }

    public function show(int $paymentQr): StreamedResponse
    {
        return $this->paymentQrService->streamImage($paymentQr);
    }
}
