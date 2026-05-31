<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\Tenant;

class TenantListItem extends BaseDataObject
{
    public string $name;
    public ?string $subdomain;
    public string $code;
    public int $updateKey;
    public ?string $currentPlan;
    public string $status;

    public static function fromModel(Tenant $tenant): self
    {
        $detail = new self();
        $detail->name = $tenant->name;
        $detail->subdomain = $tenant->subdomain;
        $detail->code = $tenant->tenant_code;
        $detail->updateKey = (int) $tenant->update_key;
        $detail->currentPlan = $tenant->license?->plan_type;
        $detail->status = $tenant->status;

        return $detail;
    }
}
