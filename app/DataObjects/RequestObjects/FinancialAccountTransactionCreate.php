<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Enums\FinancialAccountTransactionType;
use App\Models\FinancialAccount;

class FinancialAccountTransactionCreate extends BaseDataObject
{
    public function __construct(
        public int $tenantId,
        public FinancialAccount $account,
        public FinancialAccountTransactionType $transactionType,
        public float $amount,
        public string $direction,
        public ?string $referenceNumber = null,
        public ?string $referenceType = null,
        public ?string $note = null,
        public ?int $createdBy = null,
        public ?int $relatedTransactionId = null,
        public ?int $reversedTransactionId = null,
    ) {}
}
