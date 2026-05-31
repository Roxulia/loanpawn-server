<?php

namespace App\Services\PlatformModule;

use App\Repository\ManualPaymentRequestRepository;

class PlatformBillingService
{
    public function __construct(
        private AuthService $authService,
        private ManualPaymentRequestRepository $paymentRequestRepository,
    ) {
    }

    public function getBillingOverview(): array
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');

        return [
            'payments' => $this->paymentRequestRepository->paginateByPlatformUser($platformUser->id),
            'pending_count' => $this->paymentRequestRepository->countPendingByPlatformUser($platformUser->id),
            'approved_count' => $this->paymentRequestRepository->countApprovedByPlatformUser($platformUser->id),
            'approved_total' => $this->paymentRequestRepository->totalApprovedAmountByPlatformUser($platformUser->id),
        ];
    }
}
