<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Validation\Rule;

class StoreCurrencyRequest extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $symbol,
        public int $decimalPrecision,
        public string $roundingMode,
        public ?string $adjustmentStep,
        public ?bool $isActive,
    ) {}

    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:12'],
            'decimal_precision' => ['required', 'integer', 'min:0', 'max:8'],
            'rounding_mode' => ['required', Rule::in(['HALF_UP', 'HALF_DOWN', 'HALF_EVEN', 'UP', 'DOWN'])],
            'adjustment_step' => ['nullable', 'decimal:0,8', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
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
        );
    }
}
