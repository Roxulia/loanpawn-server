<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Enums\FinancialUnit;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends BaseDataObject
{
    public function __construct(
        public string $accountType,
        public string $currencyType,
        public string $accountName,
        public float $balance = 0,
        public bool $allowNegativeBalance = false,
        public ?string $accountNumber = null,
    ) {}

    public static function rules(): array
    {
        return [
            'account_type' => ['required', 'string', 'max:30'],
            'currency_type' => ['required', 'string', 'max:12'],
            'account_name' => ['required', 'string', 'max:100'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'balance_unit' => ['nullable', 'string', Rule::enum(FinancialUnit::class), 'prohibited_without:balance'],
            'allow_negative_balance' => ['sometimes', 'boolean'],
            'account_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            accountType: $data['account_type'],
            currencyType: $data['currency_type'],
            accountName: $data['account_name'],
            balance: (float) ($data['balance'] ?? 0),
            allowNegativeBalance: (bool) ($data['allow_negative_balance'] ?? false),
            accountNumber: $data['account_number'] ?? null,
        );
    }
}
