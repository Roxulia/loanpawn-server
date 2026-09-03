<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PartialPrincipalCollectionCreate extends BaseDataObject
{
    public function __construct(
        public int $slipUpdateKey,
        public float $amount,
        public ?int $acceptAccountId,
        public ?float $reportingExchangeRate = null,
    ) {}
}
