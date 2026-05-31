<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantSettingsUpdate;
use App\Services\PlatformModule\TenantServices\TenantBrandingService;
use App\Services\PlatformModule\TenantServices\TenantContactService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use Illuminate\Support\Facades\DB;

class TenantSettingsService
{
    public function __construct(
        private TenantBrandingService $tenantBrandingService,
        private TenantContactService $tenantContactService,
        private TenantSettingService $tenantSettingService,
    ) {
    }

    public function updateCurrentTenantSettings(TenantSettingsUpdate $request): void
    {
        DB::transaction(function () use ($request): void {
            if ($request->branding !== null) {
                $this->tenantBrandingService->updateCurrentTenantBranding($request->branding);
            }

            if ($request->contact !== null) {
                $this->tenantContactService->updateCurrentTenantContact($request->contact);
            }

            if ($request->defaultUserPassword !== null) {
                $this->tenantSettingService->updateCurrentTenantDefaultUserPassword($request->defaultUserPassword);
            }
        });
    }
}
