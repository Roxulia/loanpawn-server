<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\Currency;

class CurrencyResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $symbol,
        public int $decimalPrecision,
        public string $roundingMode,
        public ?string $adjustmentStep,
        public bool $isDefault,
        public bool $isActive,
        public string $source,
        public bool $canUpdate,
        public bool $canDelete,
        public int $updateKey,
    ) {}

    public static function fromModel(Currency $currency): self
    {
        $isTenantCurrency = $currency->tenant_id !== null;

        return new self(
            id: $currency->id,
            code: $currency->code,
            name: $currency->name,
            symbol: $currency->symbol,
            decimalPrecision: $currency->decimal_precision,
            roundingMode: $currency->rounding_mode,
            adjustmentStep: $currency->adjustment_step,
            isDefault: $currency->is_default,
            isActive: $currency->is_active,
            source: $isTenantCurrency ? 'TENANT' : 'PLATFORM',
            canUpdate: $isTenantCurrency,
            canDelete: $isTenantCurrency,
            updateKey: $currency->update_key,
        );
    }
}
