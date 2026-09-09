<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantLender;

class TenantLenderDetail extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public int $updateKey,
        public string $name,
        public ?string $nrc,
        public ?string $email,
        public ?string $phone,
        public ?string $address,
        public ?string $note,
        public int $totalLoans,
        public int $activeLoans,
        public string $outstandingPrincipal,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(TenantLender $lender): self
    {
        return new self(
            id: $lender->id,
            code: $lender->code,
            updateKey: (int) $lender->update_key,
            name: $lender->person->name,
            nrc: $lender->person->nrc,
            email: $lender->person->email,
            phone: $lender->person->phone,
            address: $lender->person->address,
            note: $lender->person->note,
            totalLoans: (int) ($lender->business_loans_count ?? 0),
            activeLoans: (int) ($lender->active_loans_count ?? 0),
            outstandingPrincipal: number_format((float) ($lender->outstanding_principal ?? 0), 2, '.', ''),
            createdAt: $lender->created_at?->toISOString(),
            updatedAt: $lender->updated_at?->toISOString(),
        );
    }
}
