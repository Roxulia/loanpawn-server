<?php

namespace App\Services;

use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Tenant;
use App\Support\LogsServiceOperations;
use App\Support\TenantContext;
use App\Utility\MessageCode;
use App\Utility\Messages;

abstract class BaseTenantService
{
    use LogsServiceOperations;

    protected ?Messages $message = null;

    protected function messages(): Messages
    {
        return $this->message ??= app(Messages::class);
    }

    protected function responseMessage(MessageCode $code, array $params = []): string
    {
        return $this->messages()->responseMessage($code, $params);
    }

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
