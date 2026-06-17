<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\ManualPaymentRequest;
use App\Repository\ManualPaymentRequestRepository;
use App\Utility\FileStorageUtility;
use App\Exceptions\StoredFileNotFound;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBillingService
{
    public function __construct(
        private ManualPaymentRequestRepository $paymentRequestRepository,
        private FileStorageUtility $fileStorageUtility,
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

    public function downloadPaymentAttachment(
        int $paymentRequestId,
        int $attachmentId
    ): StreamedResponse {
        $attachment = $this->paymentRequestRepository->findAttachmentForPaymentRequest(
            $paymentRequestId,
            $attachmentId
        );

        if (! $attachment) {
            abort(404);
        }

        try {
            return $this->fileStorageUtility->retrieveFile(
                $attachment->file_path,
                'local',
                basename($attachment->file_path)
            );
        } catch (StoredFileNotFound) {
            try {
                return $this->fileStorageUtility->retrieveFile(
                    $attachment->file_path,
                    'public',
                    basename($attachment->file_path)
                );
            } catch (StoredFileNotFound) {
                abort(404);
            }
        }
    }
}
