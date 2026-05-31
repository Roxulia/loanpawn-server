<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\TenantRequest;

class TenantRequestDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public int $updateKey;
    public int $platformUserId;
    public string $requestType;
    public string $requestedPlanType;
    public ?int $extensionMonths;
    public string $requestStatus;
    public string $totalCost;
    public string $currency;
    public ?string $requestedSubdomain;
    public ?string $adminReviewNote;
    public ?string $reviewedAt;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantRequest $tenantRequest): self
    {
        $detail = new self();
        $detail->id = $tenantRequest->id;
        $detail->tenantId = $tenantRequest->tenant_id;
        $detail->updateKey = (int) $tenantRequest->update_key;
        $detail->platformUserId = $tenantRequest->platform_user_id;
        $detail->requestType = $tenantRequest->request_type;
        $detail->requestedPlanType = $tenantRequest->requested_plan_type;
        $detail->extensionMonths = $tenantRequest->extension_months;
        $detail->requestStatus = $tenantRequest->request_status;
        $detail->totalCost = number_format((float) $tenantRequest->total_cost, 2, '.', '');
        $detail->currency = $tenantRequest->currency;
        $detail->requestedSubdomain = $tenantRequest->requested_subdomain;
        $detail->adminReviewNote = $tenantRequest->admin_review_note;
        $detail->reviewedAt = $tenantRequest->reviewed_at?->toISOString();

        return $detail;
    }
}
