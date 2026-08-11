<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class UpdateFinancialAccountRequest extends BaseDataObject
{
    public function __construct(
        public string $name,
        public bool $isActive,
        public bool $isDefault,
        public ?string $accountNumber,
        public int $updateKey,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            name: $data['name'],
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
            accountNumber: $data['account_number'] ?? null,
            updateKey: (int) $data['update_key'],
        );
    }
}
