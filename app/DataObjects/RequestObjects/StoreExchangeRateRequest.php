<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class StoreExchangeRateRequest extends BaseDataObject
{
    public function __construct(
        public string $pairCode,
        public string $rate,
        public ?string $observedAt,
        public ?string $idempotencyKey,
    ) {}

    public static function rules(): array
    {
        return [
            'pair_code' => ['required', 'string', 'max:30'],
            'rate' => ['required', 'decimal:0,12', 'gt:0'],
            'observed_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            pairCode: $data['pair_code'],
            rate: $data['rate'],
            observedAt: $data['observed_at'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }
}
