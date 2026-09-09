<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantBusinessLoanCreate extends BaseDataObject
{
    public function __construct(
        public float $amount, public string $description, public ?string $lenderCode = null,
        public ?int $receiptAccountId = null, public ?string $tag = null, public bool $applyInterest = false,
        public ?float $interestRate = null, public ?int $interestTypeId = null,
        public ?float $reportingExchangeRate = null, public ?string $idempotencyKey = null,
    ) {}
}
