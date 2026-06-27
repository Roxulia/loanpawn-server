<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantCustomer;
use App\Utility\NrcHelper;

class TenantCustomerDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public string $name;
    public ?string $nrc;

    public ?string $nrc_state;
    public ?string $nrc_township;
    public ?string $nrc_citizen;
    public ?string $nrc_number;
    public ?string $email;
    public ?string $phone;
    public ?string $address;
    public int $trustScore;
    public ?string $note;
    public ?int $createdBy;
    public bool $isDeleted;
    public ?string $deletedAt;
    public ?string $createdAt;
    public ?array $loanMetrics;
    public ?array $activeSlips;

    public static function fromModel(TenantCustomer $customer): static
    {
        $detail = new static();
        $detail->id = $customer->id;
        $detail->tenantId = $customer->tenant_id;
        $detail->code = $customer->code;
        $detail->updateKey = (int) $customer->update_key;
        $detail->name = $customer->name;
        $detail->nrc = $customer->nrc;
        $detail->email = $customer->email;
        $detail->phone = $customer->phone;
        $detail->address = $customer->address;
        $detail->trustScore = (int) $customer->trust_score;
        $detail->note = $customer->note;
        $detail->createdBy = $customer->created_by;
        $detail->isDeleted = (bool) $customer->is_deleted;
        $detail->deletedAt = $customer->deleted_at?->toISOString();
        $detail->createdAt = $customer->created_at?->toISOString();
        $nrc_decomposed = NrcHelper::decomposeNRC($customer->nrc);
        $detail->nrc_state = $nrc_decomposed!==null ? $nrc_decomposed['state'] : null;
        $detail->nrc_township = $nrc_decomposed!==null ? $nrc_decomposed['township'] : null;
        $detail->nrc_citizen= $nrc_decomposed!==null ? $nrc_decomposed['citizen'] : null;
        $detail->nrc_number = $nrc_decomposed!==null ? $nrc_decomposed['number'] : null;
        return $detail;
    }

    public static function fromModelWithDetail(TenantCustomer $customer, array $loanMetrics, array $activeSlips): self
    {
        $detail = self::fromModel($customer);
        $detail->loanMetrics = $loanMetrics;
        $detail->activeSlips = $activeSlips;

        return $detail;
    }
}
