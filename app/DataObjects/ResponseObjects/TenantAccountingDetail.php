<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantAccounting;
use App\Support\AccountingReferenceMapper;

class TenantAccountingDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public int $updateKey;
    public string $description;
    public string $transactionType;
    public string $amount;
    public ?int $createdBy;
    public ?int $referenceId;
    public ?string $referenceType;
    public ?string $referenceLabel;
    public ?string $createdAt;
    public ?string $updatedAt;

    public static function fromModel(TenantAccounting $accounting): self
    {
        $detail = new self();
        $detail->id = $accounting->id;
        $detail->tenantId = $accounting->tenant_id;
        $detail->updateKey = (int) $accounting->update_key;
        $detail->description = $accounting->description;
        $detail->transactionType = $accounting->transaction_type;
        $detail->amount = (string) $accounting->amount;
        $detail->createdBy = $accounting->created_by;
        $detail->referenceId = $accounting->reference_id;
        $detail->referenceType = $accounting->reference_type;
        $detail->referenceLabel = AccountingReferenceMapper::label($accounting->reference_type);
        $detail->createdAt = $accounting->created_at?->toISOString();
        $detail->updatedAt = $accounting->updated_at?->toISOString();

        return $detail;
    }
}
