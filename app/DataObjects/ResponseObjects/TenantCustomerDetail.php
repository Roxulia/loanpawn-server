<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantCustomer;

class TenantCustomerDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public string $name;
    public ?string $email;
    public ?string $phone;
    public ?string $address;
    public int $trustScore;
    public ?string $note;
    public ?int $createdBy;
    public bool $isDeleted;
    public ?string $deletedAt;

    public static function fromModel(TenantCustomer $customer): self
    {
        $detail = new self();
        $detail->id = $customer->id;
        $detail->tenantId = $customer->tenant_id;
        $detail->code = $customer->code;
        $detail->updateKey = (int) $customer->update_key;
        $detail->name = $customer->name;
        $detail->email = $customer->email;
        $detail->phone = $customer->phone;
        $detail->address = $customer->address;
        $detail->trustScore = (int) $customer->trust_score;
        $detail->note = $customer->note;
        $detail->createdBy = $customer->created_by;
        $detail->isDeleted = (bool) $customer->is_deleted;
        $detail->deletedAt = $customer->deleted_at?->toISOString();

        return $detail;
    }
}
