<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class CorrectExchangeRateRequest extends BaseDataObject
{
    public function __construct(
        public string $buyingRate,
        public string $sellingRate,
        public string $reason,
    ) {}

    public static function rules(): array
    {
        return [
            'buying_rate' => ['required', 'decimal:0,12', 'gt:0'],
            'selling_rate' => ['required', 'decimal:0,12', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            buyingRate: $data['buying_rate'],
            sellingRate: $data['selling_rate'],
            reason: $data['reason'],
        );
    }
}
