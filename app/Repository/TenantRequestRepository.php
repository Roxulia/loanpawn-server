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
