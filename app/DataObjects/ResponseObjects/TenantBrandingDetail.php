<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantBranding;

class TenantBrandingDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public int $id;
    public int $tenantId;
    public int $updateKey;
    public ?string $logoPath;
    public ?string $faviconPath;
    public ?string $primaryColor;
    public ?string $secondaryColor;
    public ?string $accentColor;
    public ?array $slipHeaderLayout;
    public ?array $slipFooterLayout;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantBranding $branding): self
    {
        $detail = new self();
        $detail->id = $branding->id;
        $detail->tenantId = $branding->tenant_id;
        $detail->updateKey = (int) $branding->update_key;
        $detail->logoPath = $branding->logo_path;
        $detail->faviconPath = $branding->favicon_path;
        $detail->primaryColor = $branding->primary_color;
        $detail->secondaryColor = $branding->secondary_color;
        $detail->accentColor = $branding->accent_color;
        $detail->slipHeaderLayout = $branding->slip_header_layout;
        $detail->slipFooterLayout = $branding->slip_footer_layout;

        return $detail;
    }
}
