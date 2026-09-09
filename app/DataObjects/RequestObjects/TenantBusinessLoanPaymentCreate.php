<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantBusinessLoanPaymentCreate extends BaseDataObject
{
    public function __construct(
        public float $amount, public string $allocationOrder, public int $loanUpdateKey,
        public ?int $paymentAccountId = null, public ?float $reportingExchangeRate = null,
        public ?string $idempotencyKey = null,
    ) {}
}
