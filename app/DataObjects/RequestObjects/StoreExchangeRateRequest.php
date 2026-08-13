<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class StoreExchangeRateRequest extends BaseDataObject
{
    public function __construct(
        public string $pairCode,
        public string $buyingRate,
        public string $sellingRate,
        public ?string $idempotencyKey,
    ) {}

    public static function rules(): array
    {
        return [
            'pair_code' => ['required', 'string', 'max:30'],
            'buying_rate' => ['required', 'decimal:0,12', 'gt:0'],
            'selling_rate' => ['required', 'decimal:0,12', 'gt:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            pairCode: $data['pair_code'],
            buyingRate: $data['buying_rate'],
            sellingRate: $data['selling_rate'],
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }
}
