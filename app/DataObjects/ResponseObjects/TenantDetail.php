<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\Tenant;

class TenantDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $name;
    public ?string $subdomain;
    public string $code;
    public int $updateKey;
    public TenantContactDetail $tenant_contact;
    public TenantLicenseDetail $tenant_license;
    public ?TenantBrandingDetail $tenant_branding;
    public ?TenantSettingDetail $tenant_setting;

    public function __construct()
    {
        //
    }

    public static function fromModel(
        Tenant $tenant,
        TenantContactDetail $tenantContact,
        TenantLicenseDetail $tenantLicense,
        ?TenantBrandingDetail $tenantBranding = null,
        ?TenantSettingDetail $tenantSetting = null,
    ): self {
        $detail = new self();
        $detail->name = $tenant->name;
        $detail->subdomain = $tenant->subdomain;
        $detail->code = $tenant->tenant_code;
        $detail->updateKey = (int) $tenant->update_key;
        $detail->tenant_contact = $tenantContact;
        $detail->tenant_license = $tenantLicense;
        $detail->tenant_branding = $tenantBranding;
        $detail->tenant_setting = $tenantSetting;

        return $detail;
    }
}
