<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\TenantLicense;

class TenantLicenseDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $licenseKey;
    public int $updateKey;
    public string $planType;
    public ?string $expireDate;
    public string $status;
    public int $currentMonthSlipCount = 0;
    public int $currentStaffCount = 0;
    public ?string $nextPlanType = null;
    public ?string $nextPlanStartsAt = null;
    public ?string $nextPlanExpiresAt = null;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantLicense $license): self
    {
        $detail = new self();
        $detail->licenseKey = $license->license_key;
        $detail->updateKey = (int) $license->update_key;
        $detail->planType = $license->plan_type;
        $detail->expireDate = $license->expires_at?->toISOString();
        $detail->status = $license->status;
        $detail->currentMonthSlipCount = (int) $license->current_month_slip_count;
        $detail->currentStaffCount = (int) $license->current_staff_count;
        $transition = $license->scheduledPlanTransition;
        $detail->nextPlanType = $transition?->to_plan_type;
        $detail->nextPlanStartsAt = $transition?->starts_at?->toISOString();
        $detail->nextPlanExpiresAt = $transition?->expires_at?->toISOString();

        return $detail;
    }
}
