<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Carbon\CarbonImmutable;

class FinancialAccountTransactionFilter extends BaseDataObject
{
    public function __construct(
        public int $perPage = 15,
        public ?string $search = null,
        public ?string $direction = null,
        public ?string $transactionType = null,
        public ?CarbonImmutable $startAt = null,
        public ?CarbonImmutable $endAt = null,
    ) {}
}
