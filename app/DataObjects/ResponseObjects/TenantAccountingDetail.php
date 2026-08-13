<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantAccounting;
use App\Models\TenantAccountingTransactions;
use App\Support\AccountingReferenceMapper;

class TenantAccountingDetail extends BaseDataObject
{
    public int $id;

    public int $tenantId;

    public int $updateKey;

    public string $description;

    public string $transactionType;

    public string $transactionDirection;

    public ?string $accountingCategory;

    public string $amount;

    public ?int $accountingDayId;

    public ?string $businessDate;

    public ?int $currencyId;

    public ?string $reportingAmount;

    public ?string $exchangeRate;

    public ?string $occurredAt;

    public ?int $legacyAccountingId;

    public bool $isDeleted;

    public ?int $createdBy;

    public ?int $referenceId;

    public ?string $referenceType;

    public ?string $referenceLabel;

    public ?string $createdAt;

    public ?string $updatedAt;

    public static function fromModel(TenantAccounting|TenantAccountingTransactions $accounting): self
    {
        $detail = new self;
        $detail->id = $accounting->id;
        $detail->tenantId = $accounting->tenant_id;
        $detail->updateKey = (int) $accounting->update_key;
        $detail->description = $accounting->description;
        $detail->transactionType = $accounting->transaction_type;
        $detail->transactionDirection = $accounting->transaction_direction ?? $accounting->transaction_type;
        $detail->accountingCategory = $accounting->accounting_category instanceof \BackedEnum
            ? $accounting->accounting_category->value
            : $accounting->accounting_category;
        $detail->amount = (string) $accounting->amount;
        $detail->accountingDayId = $accounting->accounting_day_id;
        $detail->businessDate = $accounting->business_date?->toDateString();
        $detail->currencyId = $accounting->currency_id;
        $detail->reportingAmount = $accounting->reporting_amount === null ? null : (string) $accounting->reporting_amount;
        $detail->exchangeRate = $accounting->exchange_rate === null ? null : (string) $accounting->exchange_rate;
        $detail->occurredAt = $accounting->occurred_at?->toISOString();
        $detail->legacyAccountingId = $accounting->legacy_accounting_id;
        $detail->isDeleted = (bool) $accounting->is_deleted;
        $detail->createdBy = $accounting->created_by;
        $detail->referenceId = $accounting->reference_id;
        $detail->referenceType = $accounting->reference_type;
        $detail->referenceLabel = AccountingReferenceMapper::label($accounting->reference_type);
        $detail->createdAt = $accounting->created_at?->toISOString();
        $detail->updatedAt = $accounting->updated_at?->toISOString();

        return $detail;
    }
}
