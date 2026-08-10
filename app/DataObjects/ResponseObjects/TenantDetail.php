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
    public TenantFeatures $tenant_features;
    public ?TenantBrandingDetail $tenant_branding;
    public ?TenantSettingDetail $tenant_setting;
    public ?array $tenant_category = null;

    public function __construct()
    {
        //
    }

    public static function fromModel(
        Tenant $tenant,
        TenantContactDetail $tenantContact,
        TenantLicenseDetail $tenantLicense,
        ?TenantFeatures $tenantFeatures = null,
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
        $detail->tenant_features = $tenantFeatures ?? new TenantFeatures();
        $detail->tenant_branding = $tenantBranding;
        $detail->tenant_setting = $tenantSetting;
        $detail->tenant_category = $tenant->category ? [
            'id' => $tenant->category->id,
            'code' => $tenant->category->code,
            'name' => $tenant->category->name,
        ] : null;

        return $detail;
    }
}
