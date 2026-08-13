<?php

namespace App\Repository;

use App\Models\CoreModule\TenantSetting;
use App\Models\PlatformModule\Tenant;
use Illuminate\Support\Collection;

class TenantSettingRepository
{
    public function findByTenantIdAndKey(int $tenantId, string $key): ?TenantSetting
    {
        return TenantSetting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();
    }

    public function firstOrCreate(int $tenantId, string $key, array $data): TenantSetting
    {
        return TenantSetting::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $key,
            ],
            $data,
        );
    }

    public function currencyPreferences(int $tenantId): ?TenantSetting
    {
        return TenantSetting::query()
            ->withoutGlobalScopes()
            ->with(['defaultCurrency', 'reportingCurrency'])
            ->where('tenant_id', $tenantId)
            ->where('key', 'currency_preferences')
            ->first();
    }

    public function allTenantIds(): Collection
    {
        return Tenant::query()->orderBy('id')->pluck('id');
    }

    public function update(TenantSetting $setting, array $data): TenantSetting
    {
        $setting->update($data);

        return $setting->refresh();
    }
}
