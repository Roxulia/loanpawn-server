<?php

namespace App\Services\ExchangeRate;

use App\Repository\TenantSettingRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Carbon\CarbonImmutable;

class ExchangeRateBusinessClock
{
    public const FALLBACK_TIMEZONE = 'Asia/Yangon';

    public function __construct(
        private TenantSettingRepository $settings,
        private TenantLicenseService $licenses,
    ) {}

    public function timezone(?int $tenantId): string
    {
        if ($tenantId === null || ! $this->licenses->tenantHasFeature($tenantId, 'tenant_timezone_management')) {
            return self::FALLBACK_TIMEZONE;
        }

        $timezone = $this->settings->findByTenantIdAndKey($tenantId, 'timezone')?->value;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : self::FALLBACK_TIMEZONE;
    }

    public function now(?int $tenantId): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($tenantId));
    }
}
