<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantDebt;

class TenantDebtDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public ?int $slipId;
    public ?string $slipNo;
    public ?int $customerId;
    public ?string $customerCode;
    public ?string $customerName;
    public string $amount;
    public string $description;
    public ?string $tag;
    public bool $isPaid;
    public ?int $acceptedBy;
    public ?int $createdBy;
    public ?string $createdAt;
    public ?string $updatedAt;

    public static function fromModel(TenantDebt $debt): self
    {
        $detail = new self();
        $detail->id = $debt->id;
        $detail->tenantId = $debt->tenant_id;
        $detail->code = $debt->code;
        $detail->updateKey = (int) $debt->update_key;
        $detail->slipId = $debt->slip_id;
        $detail->slipNo = $debt->slip?->slip_no;
        $detail->customerId = $debt->customer_id;
        $detail->customerCode = $debt->customer?->code;
        $detail->customerName = $debt->customer?->name;
        $detail->amount = (string) $debt->amount;
        $detail->description = $debt->description;
        $detail->tag = $debt->tag;
        $detail->isPaid = (bool) $debt->is_paid;
        $detail->acceptedBy = $debt->accepted_by;
        $detail->createdBy = $debt->created_by;
        $detail->createdAt = $debt->created_at?->toISOString();
        $detail->updatedAt = $debt->updated_at?->toISOString();

        return $detail;
    }
}
