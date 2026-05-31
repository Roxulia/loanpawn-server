<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\ManualPaymentRequest;
use App\Repository\ManualPaymentRequestRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminBillingService
{
    public function __construct(
        private ManualPaymentRequestRepository $paymentRequestRepository,
    ) {
    }

    public function getBillingOverview(): array
    {
        return [
            'payments' => $this->paymentRequestRepository->paginateAll(),
            'pending_count' => $this->paymentRequestRepository->countPendingApproval(),
            'approved_count' => $this->paymentRequestRepository->countApproved(),
            'approved_total' => $this->paymentRequestRepository->totalApprovedAmount(),
        ];
    }

    public function pendingPaymentRequests(): LengthAwarePaginator
    {
        return $this->paymentRequestRepository->paginatePendingApproval();
    }

    public function findPaymentRequest(int $id): ManualPaymentRequest
    {
        $paymentRequest = $this->paymentRequestRepository->findById($id);

        if (! $paymentRequest) {
            abort(404);
        }

        return $paymentRequest;
    }
}
