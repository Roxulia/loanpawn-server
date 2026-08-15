<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class FinancialMovementSummary extends BaseDataObject
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $effectiveReportingCurrencyId,
        public array $accounting,
        public array $financialAccounts,
    ) {}
}
