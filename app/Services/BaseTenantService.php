<?php

namespace App\Services;

use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Tenant;
use App\Support\LogsServiceOperations;
use App\Support\TenantContext;

abstract class BaseTenantService
{
    use LogsServiceOperations;

    protected function resolveCurrentTenantId(): int
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw new TenantNotFound('Current tenant is not resolved.');
        }

        return $tenantId;
    }

    protected function getCurrentTenantName(): string
    {
        $tenant = app(TenantContext::class)->name();

        if ($tenant === null) {
            throw new TenantNotFound('Current tenant is not resolved.');
        }

        return $tenant;
    }

    protected function resolveCurrentTenantCode(): string
    {
        $tenantId = $this->resolveCurrentTenantId();
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFound('Current tenant is not resolved.');
        }

        return $tenant->tenant_code;
    }
}
