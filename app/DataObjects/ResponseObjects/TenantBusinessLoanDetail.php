<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantBusinessLoan;

class TenantBusinessLoanDetail extends BaseDataObject
{
    public function __construct(public array $loan) {}

    public static function fromModel(TenantBusinessLoan $loan): self
    {
        $interest = (float) $loan->outstanding_interest;
        return new self([
            'id' => $loan->id, 'code' => $loan->code, 'update_key' => (int) $loan->update_key,
            'lender_id' => $loan->lender_id, 'lender_code' => $loan->lender?->code,
            'lender_name' => $loan->lender?->person?->name ?? 'Unknown Lender',
            'receipt_account_id' => $loan->receipt_account_id, 'amount' => (string) $loan->amount,
            'principal_balance' => (string) $loan->principal_balance, 'apply_interest' => (bool) $loan->apply_interest,
            'interest_rate' => $loan->interest_rate === null ? null : (string) $loan->interest_rate,
            'interest_type_id' => $loan->interest_type_id, 'interest_type_name' => $loan->interestType?->name,
            'outstanding_interest' => number_format($interest, 2, '.', ''),
            'total_outstanding' => number_format((float) $loan->principal_balance + $interest, 2, '.', ''),
            'compound_schedule_enabled' => (bool) $loan->compound_schedule_enabled,
            'compound_every' => $loan->compound_every, 'compound_every_type' => $loan->compound_every_type,
            'next_compound_at' => $loan->next_compound_at?->toISOString(), 'last_compounded_at' => $loan->last_compounded_at?->toISOString(),
            'description' => $loan->description, 'tag' => $loan->tag, 'is_paid' => (bool) $loan->is_paid,
            'created_at' => $loan->created_at?->toISOString(), 'updated_at' => $loan->updated_at?->toISOString(),
        ]);
    }

    public function toArray(): array { return $this->loan; }
}
