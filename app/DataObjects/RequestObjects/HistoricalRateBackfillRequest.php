<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class HistoricalRateBackfillRequest extends BaseDataObject
{
    public function __construct(public int $recalculationId, public array $rates) {}

    public static function rules(): array
    {
        return [
            'recalculation_id' => ['required', 'integer', 'min:1'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.requirement_key' => ['required', 'string', 'size:64'],
            'rates.*.buying_open' => ['required', 'decimal:0,12', 'gt:0'],
            'rates.*.buying_close' => ['required', 'decimal:0,12', 'gt:0'],
            'rates.*.selling_open' => ['required', 'decimal:0,12', 'gt:0'],
            'rates.*.selling_close' => ['required', 'decimal:0,12', 'gt:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self((int) $data['recalculation_id'], array_values($data['rates']));
    }
}
