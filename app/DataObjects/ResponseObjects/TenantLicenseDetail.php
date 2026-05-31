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

        return $detail;
    }
}
