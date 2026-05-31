<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Carbon\Carbon;

class LedgerEntry extends BaseDataObject
{
    public function __construct(
        public int $id,
        public Carbon $createdAt,
        public string $description,
        public float $debit,
        public float $credit,
        public float $balance,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?string $referenceLabel,
    ) {
    }
}
