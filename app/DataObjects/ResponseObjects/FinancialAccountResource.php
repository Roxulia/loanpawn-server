<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\FinancialAccount;

class FinancialAccountResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $accountCode,
        public string $accountName,
        public ?string $accountNumber,
        public string $balance,
        public bool $isActive,
        public bool $isDefault,
        public bool $isDeleted,
        public bool $allowNegativeBalance,
        public int $updateKey,
        public array $accountType,
        public array $currency,
    ) {}

    public static function fromModel(FinancialAccount $account): self
    {
        return new self(
            id: $account->id,
            accountCode: $account->account_code,
            accountName: $account->account_name,
            accountNumber: $account->account_number,
            balance: (string) $account->balance,
            isActive: (bool) $account->is_active,
            isDefault: (bool) $account->is_default,
            isDeleted: (bool) $account->is_deleted,
            allowNegativeBalance: (bool) $account->allow_negative_balance,
            updateKey: (int) $account->update_key,
            accountType: [
                'id' => $account->accountType->id,
                'code' => $account->accountType->code,
                'name' => $account->accountType->name,
            ],
            currency: [
                'id' => $account->currency->id,
                'code' => $account->currency->code,
                'name' => $account->currency->name,
                'symbol' => $account->currency->symbol,
            ],
        );
    }
}
