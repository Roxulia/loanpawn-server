<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnLoanContractSlip;

class LoanContractSlipDetail extends BaseDataObject
{
    public int $id;

    public int $tenantId;

    public string $slipNo;

    public int $customerId;

    public ?int $accountId;

    public ?TenantCustomerDetail $customer;

    public string $loanAmount;

    public string $interestRate;

    public ?int $interestTypeId;

    public ?string $interestTypeCode;

    public ?string $interestTypeName;

    public ?string $expireAt;

    public ?string $lastInterestAddedAt;

    public ?string $lastInterestPaidAt;

    public string $status;

    public ?string $notes;

    public ?int $createdBy;

    public int $expiryQuota;

    public string $expiryQuotaType;

    public bool $compoundScheduleEnabled;

    public ?int $compoundEvery;

    public ?string $compoundEveryType;

    public ?string $nextCompoundAt;

    public ?string $lastCompoundedAt;

    /**
     * @var LoanContractSlipItemDetail[]
     */
    public array $items;

    public ?string $createdAt;

    public ?string $updatedAt;

    public int $updateKey;

    public static function fromModel(PawnLoanContractSlip $slip): self
    {
        $detail = new self;
        $detail->id = $slip->id;
        $detail->tenantId = $slip->tenant_id;
        $detail->slipNo = $slip->slip_no;
        $detail->customerId = $slip->customer_id;
        $detail->accountId = $slip->account_id;
        $detail->customer = $slip->relationLoaded('customer') && $slip->customer !== null
            ? TenantCustomerDetail::fromModel($slip->customer)
            : null;
        $detail->loanAmount = (string) $slip->loan_amount;
        $detail->interestRate = (string) $slip->interest_rate;
        $detail->interestTypeId = $slip->interest_type_id;
        $detail->interestTypeCode = $slip->interestType?->code;
        $detail->interestTypeName = $slip->interestType?->name;
        $detail->expireAt = $slip->expire_at?->toISOString();
        $detail->lastInterestAddedAt = $slip->last_interest_added_at?->toISOString();
        $detail->lastInterestPaidAt = $slip->last_interest_paid_at?->toISOString();
        $detail->status = $slip->status;
        $detail->notes = $slip->notes;
        $detail->createdBy = $slip->created_by;
        $detail->expiryQuota = (int) $slip->expiry_quota;
        $detail->expiryQuotaType = $slip->expiry_quota_type;
        $detail->compoundScheduleEnabled = (bool) $slip->compound_schedule_enabled;
        $detail->compoundEvery = $slip->compound_every === null ? null : (int) $slip->compound_every;
        $detail->compoundEveryType = $slip->compound_every_type;
        $detail->nextCompoundAt = $slip->next_compound_at?->toISOString();
        $detail->lastCompoundedAt = $slip->last_compounded_at?->toISOString();
        $detail->items = $slip->relationLoaded('slipItems')
            ? $slip->slipItems->map(fn ($item) => LoanContractSlipItemDetail::fromModel($item))->all()
            : [];
        $detail->createdAt = $slip->created_at?->toISOString();
        $detail->updatedAt = $slip->updated_at?->toISOString();
        $detail->updateKey = (int) $slip->update_key;

        return $detail;
    }
}
