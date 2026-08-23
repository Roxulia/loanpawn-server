<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\FinancialAccount;

class FinancialAccountSummary extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $accountCode,
        public string $accountName,
        public bool $isActive,
        public array $currency,
    ) {}

    public static function fromModel(FinancialAccount $account): self
    {
        return new self(
            id: $account->id,
            accountCode: $account->account_code,
            accountName: $account->account_name,
            isActive: (bool) $account->is_active,
            currency: [
                'id' => $account->currency->id,
                'code' => $account->currency->code,
                'symbol' => $account->currency->symbol,
            ],
        );
    }
}
