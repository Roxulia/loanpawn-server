<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtPaymentResult extends BaseDataObject
{
    public function __construct(
        public string $status,
        public string $debtCode,
        public string $allocationOrder,
        public float $paymentAmount,
        public float $principalPaid,
        public float $interestPaid,
        public float $changeAmount,
        public float $remainingPrincipal,
        public float $remainingInterest,
        public bool $isPaid,
        public int $updateKey,
        public ?int $acceptAccountId,
    ) {}
}
