<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Enums\AccountingCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class TenantAccountingTransactionRecord extends BaseDataObject
{
    public function __construct(
        public ?Model $reference,
        public string $description,
        public string $transactionDirection,
        public AccountingCategory $accountingCategory,
        public float $amount,
        public ?int $createdBy = null,
        public ?int $currencyId = null,
        public ?float $exchangeRate = null,
        public ?float $reportingAmount = null,
        public ?CarbonInterface $occurredAt = null,
        public ?int $legacyAccountingId = null,
    ) {}
}
