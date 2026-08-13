<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCurrencySettingsUpdate extends BaseDataObject
{
    public function __construct(
        public int $defaultCurrencyId,
        public int $reportingCurrencyId,
        public int $updateKey,
    ) {}

    public static function rules(): array
    {
        return [
            'default_currency_id' => ['required', 'integer', 'min:1'],
            'reporting_currency_id' => ['required', 'integer', 'min:1'],
            'update_key' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            defaultCurrencyId: (int) $data['default_currency_id'],
            reportingCurrencyId: (int) $data['reporting_currency_id'],
            updateKey: (int) $data['update_key'],
        );
    }
}
