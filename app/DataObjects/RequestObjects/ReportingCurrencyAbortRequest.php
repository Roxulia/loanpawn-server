<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class ReportingCurrencyAbortRequest extends BaseDataObject
{
    public function __construct(public int $recalculationId, public int $updateKey) {}

    public static function rules(): array
    {
        return [
            'recalculation_id' => ['required', 'integer', 'min:1'],
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self((int) $data['recalculation_id'], (int) $data['update_key']);
    }
}
