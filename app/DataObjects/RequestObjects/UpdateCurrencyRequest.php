<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class UpdateCurrencyRequest extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $symbol,
        public int $decimalPrecision,
        public string $roundingMode,
        public ?string $adjustmentStep,
        public ?bool $isActive,
        public int $updateKey,
    ) {}

    public static function rules(): array
    {
        return StoreCurrencyRequest::rules() + [
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            code: $data['code'],
            name: $data['name'],
            symbol: $data['symbol'] ?? null,
            decimalPrecision: (int) $data['decimal_precision'],
            roundingMode: $data['rounding_mode'],
            adjustmentStep: $data['adjustment_step'] ?? null,
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            updateKey: (int) $data['update_key'],
        );
    }
}
