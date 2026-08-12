<?php

namespace App\Services\TenantModule;

use App\Repository\TenantSettingRepository;
use Carbon\CarbonImmutable;

class AccountingDayBusinessClock
{
    public const FALLBACK_TIMEZONE = 'Asia/Yangon';

    public function __construct(private TenantSettingRepository $settings) {}

    public function timezone(int $tenantId): string
    {
        $timezone = $this->settings->findByTenantIdAndKey($tenantId, 'timezone')?->value;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : self::FALLBACK_TIMEZONE;
    }

    public function now(int $tenantId): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($tenantId));
    }
}
