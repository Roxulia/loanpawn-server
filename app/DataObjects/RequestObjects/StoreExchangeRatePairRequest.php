<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class StoreExchangeRatePairRequest extends BaseDataObject
{
    public function __construct(
        public string $baseCurrencyCode,
        public string $quoteCurrencyCode,
        public ?bool $isActive,
    ) {}

    public static function rules(): array
    {
        return [
            'base_currency_code' => ['required', 'string', 'max:12'],
            'quote_currency_code' => ['required', 'string', 'max:12', 'different:base_currency_code'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            baseCurrencyCode: $data['base_currency_code'],
            quoteCurrencyCode: $data['quote_currency_code'],
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        );
    }
}
