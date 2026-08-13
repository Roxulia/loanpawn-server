<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class StoreFinancialAccountTypeRequest extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}

    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(code: $data['code'], name: $data['name']);
    }
}
