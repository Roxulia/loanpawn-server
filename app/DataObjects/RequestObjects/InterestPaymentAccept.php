<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\DataObjects\ResponseObjects\InterestBreakDown;

class InterestPaymentAccept extends BaseDataObject
{
    /**
     * @param  InterestBreakDown[]  $interestBreakdown
     */
    public function __construct(
        public int $slipUpdateKey,
        public float $paymentAmount,
        public int $acceptAccountId,
        public bool $recordDebt,
        public array $interestBreakdown,
        public ?string $idempotencyKey = null,
    ) {}
}
