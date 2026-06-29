<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantCapital;

class TenantCapitalDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public string $description;
    public string $amount;
    public ?int $createdBy;
    public ?string $createdAt;
    public ?string $updatedAt;

    public static function fromModel(TenantCapital $capital): self
    {
        $detail = new self();
        $detail->id = $capital->id;
        $detail->tenantId = $capital->tenant_id;
        $detail->code = $capital->code;
        $detail->updateKey = (int) $capital->update_key;
        $detail->description = $capital->description;
        $detail->amount = (string) $capital->amount;
        $detail->createdBy = $capital->created_by;
        $detail->createdAt = $capital->created_at?->toISOString();
        $detail->updatedAt = $capital->updated_at?->toISOString();

        return $detail;
    }
}
