<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtPaymentCreate extends BaseDataObject
{
    public function __construct(
        public int $debtId,
        public float $paymentAmount,
        public string $allocationOrder = 'interest_first',
        public ?int $acceptAccountId = null,
        public ?float $reportingExchangeRate = null,
        public ?int $debtUpdateKey = null,
        public ?string $idempotencyKey = null,
    ) {}
}
