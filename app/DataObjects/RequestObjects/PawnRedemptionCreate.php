<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Carbon\CarbonInterface;

class PawnRedemptionCreate extends BaseDataObject
{
    public function __construct(
        public string $slipNo,
        public float $calculatedTotal,
        public float $paymentAmount,
        public ?int $accountId,
        public array $debts,
        public array $interests,
        public ?float $reportingExchangeRate = null,
        public ?CarbonInterface $redemptionAt = null,
        public ?string $notes = null,
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {}
}
