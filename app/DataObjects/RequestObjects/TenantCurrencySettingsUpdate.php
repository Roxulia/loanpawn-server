<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Enums\FinancialUnit;
use Illuminate\Validation\Rule;

class TenantCurrencySettingsUpdate extends BaseDataObject
{
    public function __construct(
        public int $defaultCurrencyId,
        public int $reportingCurrencyId,
        public int $updateKey,
        public ?string $defaultFinancialUnit = null,
        public bool $hasDefaultFinancialUnit = false,
    ) {}

    public static function rules(): array
    {
        return [
            'default_currency_id' => ['required', 'integer', 'min:1'],
            'reporting_currency_id' => ['required', 'integer', 'min:1'],
            'update_key' => ['required', 'integer', 'min:0'],
            'default_financial_unit' => ['sometimes', 'nullable', 'string', Rule::enum(FinancialUnit::class)],
        ];
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            defaultCurrencyId: (int) $data['default_currency_id'],
            reportingCurrencyId: (int) $data['reporting_currency_id'],
            updateKey: (int) $data['update_key'],
            defaultFinancialUnit: $data['default_financial_unit'] ?? null,
            hasDefaultFinancialUnit: array_key_exists('default_financial_unit', $data),
        );
    }
}
