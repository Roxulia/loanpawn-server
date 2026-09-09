<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantBusinessLoanUpdate extends BaseDataObject
{
    public function __construct(
        public int $updateKey, public ?string $lenderCode, public string $description,
        public ?string $tag = null, public ?float $amount = null, public ?int $receiptAccountId = null,
        public ?float $reportingExchangeRate = null,
    ) {}
}
