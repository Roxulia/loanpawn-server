<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantSetting;

class TenantCurrencySettingsResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $defaultCurrencyId,
        public int $reportingCurrencyId,
        public int $updateKey,
        public CurrencyResource $defaultCurrency,
        public CurrencyResource $reportingCurrency,
    ) {}

    public static function fromModel(TenantSetting $setting): self
    {
        return new self(
            id: $setting->id,
            tenantId: $setting->tenant_id,
            defaultCurrencyId: $setting->default_currency_id,
            reportingCurrencyId: $setting->reporting_currency_id,
            updateKey: $setting->update_key,
            defaultCurrency: CurrencyResource::fromModel($setting->defaultCurrency),
            reportingCurrency: CurrencyResource::fromModel($setting->reportingCurrency),
        );
    }
}
