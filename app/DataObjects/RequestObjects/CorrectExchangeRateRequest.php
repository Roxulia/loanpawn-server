<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class CorrectExchangeRateRequest extends BaseDataObject
{
    public function __construct(
        public string $rate,
        public string $reason,
    ) {}

    public static function rules(): array
    {
        return [
            'rate' => ['required', 'decimal:0,12', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            rate: $data['rate'],
            reason: $data['reason'],
        );
    }
}
