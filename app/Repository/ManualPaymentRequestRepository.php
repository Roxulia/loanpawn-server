<?php

namespace App\Repository;

use App\Models\PlatformModule\ManualPaymentAttachment;
use App\Models\PlatformModule\ManualPaymentRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ManualPaymentRequestRepository
{
    public function paginateAll(int $perPage = 15): LengthAwarePaginator
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->with(['platformUser', 'tenant', 'tenantRequest', 'attachments', 'reviewer'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginatePendingApproval(int $perPage = 15): LengthAwarePaginator
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->with(['platformUser', 'tenant', 'tenantRequest', 'attachments'])
            ->whereHas('tenantRequest', fn ($query) => $query->where('request_status', 'pending_approval'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?ManualPaymentRequest
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->with(['platformUser', 'tenant.license', 'tenantRequest', 'attachments', 'reviewer'])
            ->find($id);
    }

    public function findAttachmentForPaymentRequest(
        int $paymentRequestId,
        int $attachmentId
    ): ?ManualPaymentAttachment {
        return ManualPaymentAttachment::query()
            ->where('id', $attachmentId)
            ->where('is_deleted', false)
            ->whereHas('manualPaymentRequest', function ($query) use ($paymentRequestId) {
                $query
                    ->where('id', $paymentRequestId)
                    ->where('is_deleted', false);
            })
            ->first();
    }

    public function paginateByPlatformUser(int $platformUserId, int $perPage = 15): LengthAwarePaginator
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->with(['tenant', 'tenantRequest', 'attachments'])
            ->where('platform_user_id', $platformUserId)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function countPendingByPlatformUser(int $platformUserId): int
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->where('platform_user_id', $platformUserId)
            ->whereIn('status', ['submitted', 'under_review'])
            ->count();
    }

    public function countApprovedByPlatformUser(int $platformUserId): int
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->where('platform_user_id', $platformUserId)
            ->where('status', 'approved')
            ->count();
    }

    public function totalApprovedAmountByPlatformUser(int $platformUserId): float
    {
        return (float) ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->where('platform_user_id', $platformUserId)
            ->where('status', 'approved')
            ->sum('amount');
    }

    public function countPendingApproval(): int
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->whereHas('tenantRequest', fn ($query) => $query->where('request_status', 'pending_approval'))
            ->count();
    }

    public function countApproved(): int
    {
        return ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->where('status', 'approved')
            ->count();
    }

    public function totalApprovedAmount(): float
    {
        return (float) ManualPaymentRequest::query()
            ->where('is_deleted', false)
            ->where('status', 'approved')
            ->sum('amount');
    }
}
