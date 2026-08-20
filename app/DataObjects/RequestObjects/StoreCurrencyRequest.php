<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
class StoreCurrencyRequest extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $symbol,
        public ?bool $isActive,
    ) {}

    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            code: $data['code'],
            name: $data['name'],
            symbol: $data['symbol'] ?? null,
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        );
    }
}
