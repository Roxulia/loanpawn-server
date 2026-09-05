<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtInterestCalculation extends BaseDataObject
{
    public function __construct(
        public string $debtCode,
        public int $debtUpdateKey,
        public ?int $accountId,
        public string $currentDate,
        public string $originalPrincipal,
        public string $principalBalance,
        public string $outstandingInterest,
        public string $totalOutstanding,
        public bool $applyInterest,
        public ?string $interestRate,
        public ?int $interestTypeId,
        public ?string $interestTypeName,
        public bool $allowPartialPayments,
        public array $interestBreakdown,
    ) {}
}
