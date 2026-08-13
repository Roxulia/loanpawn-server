<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class VoidExchangeRateRequest extends BaseDataObject
{
    public function __construct(public string $reason) {}

    public static function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    public static function fromValidated(array $data): self
    {
        return new self(reason: $data['reason']);
    }
}
