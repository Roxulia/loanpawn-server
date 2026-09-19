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

    public ?int $createdAccountId;

    public ?int $acceptAccountId;

    public ?int $slipId;

    public ?string $slipNo;

    public ?int $customerId;

    public ?string $customerCode;

    public ?string $customerName;

    public string $amount;

    public bool $applyInterest;

    public string $principalBalance;

    public ?string $interestRate;

    public ?int $interestTypeId;

    public ?string $interestTypeName;

    public bool $compoundScheduleEnabled;

    public ?int $compoundEvery;

    public ?string $compoundEveryType;

    public ?string $nextCompoundAt;

    public ?string $lastCompoundedAt;

    public string $outstandingInterest;

    public string $totalOutstanding;

    public string $description;

    public ?string $tag;

    public bool $isPaid;

    public ?int $acceptedBy;

    public ?int $createdBy;

    public ?string $createdAt;

    public ?string $updatedAt;

    public static function fromModel(TenantDebt $debt): self
    {
        $detail = new self;
        $detail->id = $debt->id;
        $detail->tenantId = $debt->tenant_id;
        $detail->code = $debt->code;
        $detail->updateKey = (int) $debt->update_key;
        $detail->createdAccountId = $debt->created_account_id;
        $detail->acceptAccountId = $debt->accept_account_id;
        $detail->slipId = $debt->slip_id;
        $detail->slipNo = $debt->slip?->slip_no;
        $detail->customerId = $debt->customer_id;
        $detail->customerCode = $debt->customer?->code;
        $detail->customerName = $debt->customer?->name;
        $detail->amount = (string) $debt->amount;
        $detail->applyInterest = (bool) $debt->apply_interest;
        $detail->principalBalance = (string) $debt->principal_balance;
        $detail->interestRate = $debt->interest_rate === null ? null : (string) $debt->interest_rate;
        $detail->interestTypeId = $debt->interest_type_id;
        $detail->interestTypeName = $debt->interestType?->name;
        $detail->compoundScheduleEnabled = (bool) $debt->compound_schedule_enabled;
        $detail->compoundEvery = $debt->compound_every;
        $detail->compoundEveryType = $debt->compound_every_type;
        $detail->nextCompoundAt = $debt->next_compound_at?->toISOString();
        $detail->lastCompoundedAt = $debt->last_compounded_at?->toISOString();
        $detail->outstandingInterest = number_format((float) ($debt->outstanding_interest ?? 0), 2, '.', '');
        $detail->totalOutstanding = number_format((float) $debt->principal_balance + (float) ($debt->outstanding_interest ?? 0), 2, '.', '');
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
