<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantSetting;
use App\Models\ReportingCurrencyRecalculation;

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
        public int $effectiveReportingCurrencyId,
        public CurrencyResource $effectiveReportingCurrency,
        public ?array $reportingCurrencyRecalculation,
    ) {}

    public static function fromModel(TenantSetting $setting, ?ReportingCurrencyRecalculation $recalculation = null): self
    {
        $effectiveCurrency = $recalculation?->previousReportingCurrency ?? $setting->reportingCurrency;

        return new self(
            id: $setting->id,
            tenantId: $setting->tenant_id,
            defaultCurrencyId: $setting->default_currency_id,
            reportingCurrencyId: $setting->reporting_currency_id,
            updateKey: $setting->update_key,
            defaultCurrency: CurrencyResource::fromModel($setting->defaultCurrency),
            reportingCurrency: CurrencyResource::fromModel($setting->reportingCurrency),
            effectiveReportingCurrencyId: (int) $effectiveCurrency->id,
            effectiveReportingCurrency: CurrencyResource::fromModel($effectiveCurrency),
            reportingCurrencyRecalculation: $recalculation === null ? null : [
                'id' => $recalculation->id,
                'status' => $recalculation->status,
                'window_start' => $recalculation->window_start->toDateString(),
                'window_end' => $recalculation->window_end->toDateString(),
                'missing_rates' => $recalculation->missing_rates ?? [],
            ],
        );
    }
}
