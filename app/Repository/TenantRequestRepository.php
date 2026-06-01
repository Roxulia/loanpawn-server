<?php

namespace App\Repository;

use App\Models\PlatformModule\ManualPaymentAttachment;
use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\TenantRequest;
use App\Exceptions\RequiredValueMissing;

class TenantRequestRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function create(array $data): TenantRequest
    {
        $this->requireValue($data, 'code', 'Tenant request');

        return TenantRequest::query()->create($data);
    }

    public function findById(int $id): ?TenantRequest
    {
        return TenantRequest::query()->find($id);
    }

    public function findOpenPlanChangeByTenantId(int $tenantId): ?TenantRequest
    {
        return TenantRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('request_type', 'plan_change')
            ->where('is_deleted', false)
            ->where(function ($query) {
                $query->whereIn('request_status', ['waiting_payment', 'pending_approval'])
                    ->orWhere(function ($query) {
                        $query->where('request_status', 'approved')
                            ->whereHas('planTransition', fn ($query) => $query->where('status', 'scheduled'));
                    });
            })
            ->latest('id')
            ->first();
    }

    public function softDeleteDraftPlanChange(TenantRequest $tenantRequest): void
    {
        TenantRequest::query()->whereKey($tenantRequest->id)->update(['is_deleted' => true]);

        ManualPaymentRequest::query()
            ->where('tenant_request_id', $tenantRequest->id)
            ->where('status', 'draft')
            ->update(['is_deleted' => true]);
    }

    public function createManualPaymentRequest(array $data): ManualPaymentRequest
    {
        $this->requireValue($data, 'code', 'Manual payment request');

        return ManualPaymentRequest::query()->create($data);
    }

    public function findManualPaymentRequestByTenantRequestId(int $tenantRequestId): ?ManualPaymentRequest
    {
        return ManualPaymentRequest::query()
            ->where('tenant_request_id', $tenantRequestId)
            ->latest('id')
            ->first();
    }

    public function updateManualPaymentRequest(ManualPaymentRequest $manualPaymentRequest, array $data): ManualPaymentRequest
    {
        $manualPaymentRequest->update($data);

        return $manualPaymentRequest->refresh();
    }

    public function updateTenantRequest(TenantRequest $tenantRequest, array $data): TenantRequest
    {
        $tenantRequest->update($data);

        return $tenantRequest->refresh();
    }

    public function createManualPaymentAttachment(array $data): ManualPaymentAttachment
    {
        $this->requireValue($data, 'code', 'Manual payment attachment');

        return ManualPaymentAttachment::query()->create($data);
    }

    protected function requireValue(array $data, string $key, string $label): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("{$label} {$key} is required.");
        }
    }
}
