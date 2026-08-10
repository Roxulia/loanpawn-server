<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class UpdateExchangeRatePairRequest extends BaseDataObject
{
    public function __construct(
        public string $baseCurrencyCode,
        public string $quoteCurrencyCode,
        public ?bool $isActive,
        public int $updateKey,
    ) {}

    public static function rules(): array
    {
        return StoreExchangeRatePairRequest::rules() + [
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            baseCurrencyCode: $data['base_currency_code'],
            quoteCurrencyCode: $data['quote_currency_code'],
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            updateKey: (int) $data['update_key'],
        );
    }
}
