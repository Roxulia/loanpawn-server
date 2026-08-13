<?php

namespace App\DataObjects\RequestObjects;

class UpdateFinancialAccountTypeRequest extends StoreFinancialAccountTypeRequest
{
    public function __construct(
        string $code,
        string $name,
        public int $updateKey,
    ) {
        parent::__construct($code, $name);
    }

    public static function rules(): array
    {
        return parent::rules() + [
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(code: $data['code'], name: $data['name'], updateKey: (int) $data['update_key']);
    }
}
