<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class LoanContractSlipCreate extends BaseDataObject
{
    /**
     * @param  array<int, PawnCollateralItemCreate>  $collateralItems
     */
    public function __construct(
        public TenantCustomerCreate $customer,
        public array $collateralItems,
        public float $loanAmount,
        public float $interestRate,
        public ?int $accountId,
        public ?float $reportingExchangeRate = null,
        public ?int $interestTypeId = null,
        public ?string $notes = null,
        public int $expiryQuota = 0,
        public string $expiryQuotaType = 'Day',
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {}
}
