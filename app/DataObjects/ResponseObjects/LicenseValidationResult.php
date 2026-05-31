<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\TenantLicense;

class LicenseValidationResult extends BaseDataObject
{
    public bool $valid;
    public ?string $message;
    public ?TenantLicenseDetail $license;
    public ?string $tenantCode;
    public ?string $tenantName;
    public ?string $subdomain;

    public static function valid(TenantLicense $license): self
    {
        $result = new self();
        $result->valid = true;
        $result->message = 'License is valid.';
        $result->license = TenantLicenseDetail::fromModel($license);
        $result->tenantCode = $license->tenant?->tenant_code;
        $result->tenantName = $license->tenant?->name;
        $result->subdomain = $license->tenant?->subdomain;

        return $result;
    }

    public static function invalid(string $message, ?TenantLicense $license = null): self
    {
        $result = new self();
        $result->valid = false;
        $result->message = $message;
        $result->license = $license === null ? null : TenantLicenseDetail::fromModel($license);
        $result->tenantCode = $license?->tenant?->tenant_code;
        $result->tenantName = $license?->tenant?->name;
        $result->subdomain = $license?->tenant?->subdomain;

        return $result;
    }
}
