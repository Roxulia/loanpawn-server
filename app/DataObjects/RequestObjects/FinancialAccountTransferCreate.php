<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class FinancialAccountTransferCreate extends BaseDataObject
{
    public function __construct(
        public int $fromAccountId,
        public int $toAccountId,
        public float $fromAmount,
        public ?float $exchangeRate = null,
        public ?float $feeReportingExchangeRate = null,
        public float $feeAmount = 0,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {}
}
