<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\RequestObjects\TenantDefaultUserPasswordUpdate;
use App\Exceptions\AlreadyUpdatedException;
use App\Models\CoreModule\TenantSetting;
use App\Repository\TenantSettingRepository;
use App\Services\BaseTenantService;

class TenantSettingService extends BaseTenantService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private TenantSettingRepository $repository,
    )
    {
        //
    }

    public function createDefaultTenantSettings(int $tenantId): void
    {
        // Implement logic to create default tenant settings for the given tenant ID
        $defaultSettings = [
            'default_tenant_user_password' => '12345678',
            // Add more default settings as needed
        ];
        foreach ($defaultSettings as $key => $value) {
            TenantSetting::create([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    public function getTenantDefaultUserPassword(int $tenantId): ?string
    {
        $setting = $this->repository->findByTenantIdAndKey($tenantId, 'default_tenant_user_password');

        return $setting?->value !== null ? (string) $setting->value : null;
    }

    public function getCurrentTenantDefaultUserPassword(): string
    {
        return $this->getTenantDefaultUserPassword($this->resolveCurrentTenantId()) ?? '12345678';
    }

    public function updateCurrentTenantDefaultUserPassword(TenantDefaultUserPasswordUpdate $request): TenantSetting
    {
        $setting = $this->getSetting($this->resolveCurrentTenantId(), 'default_tenant_user_password');

        if ((int) $setting->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        return $this->repository->update($setting, [
            'value' => $request->defaultTenantUserPassword,
            'category' => 'tenant',
            'update_key' => $setting->update_key + 1,
        ]);
    }

    public function updateTenantDefaultUserPassword(int $tenantId, string $newPassword, int $updateKey): TenantSetting
    {
        $setting = $this->getSetting($tenantId, 'default_tenant_user_password');

        if ((int) $setting->update_key !== $updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        return $this->repository->update($setting, [
            'value' => $newPassword,
            'category' => 'tenant',
            'update_key' => $setting->update_key + 1,
        ]);
    }

    private function getSetting(int $tenantId, string $code): TenantSetting
    {
        return $this->repository->firstOrCreate(
            $tenantId,
            $code,
            [
                'value' => $code === 'default_tenant_user_password' ? '12345678' : null,
                'category' => 'tenant',
            ],
        );
    }
}
