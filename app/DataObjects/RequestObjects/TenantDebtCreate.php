<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtCreate extends BaseDataObject
{
    public function __construct(
        public float $amount,
        public string $description,
        public ?int $createdAccountId = null,
        public bool $applyInterest = false,
        public ?float $interestRate = null,
        public ?int $interestTypeId = null,
        public ?float $reportingExchangeRate = null,
        public ?int $slipId = null,
        public ?string $slipCode = null,
        public ?string $customerCode = null,
        public ?string $tag = null,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {}
}
