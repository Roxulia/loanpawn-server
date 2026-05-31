<?php

namespace App\Repository;

use App\Models\CoreModule\TenantSetting;

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
        return TenantSetting::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $key,
            ],
            $data,
        );
    }

    public function update(TenantSetting $setting, array $data): TenantSetting
    {
        $setting->update($data);

        return $setting->refresh();
    }
}
