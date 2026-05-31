<?php

namespace App\Repository;

use App\Models\CoreModule\TenantBranding;
use App\Exceptions\RequiredValueMissing;

class TenantBrandingRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function isBrandingExisted(int $tenantId):bool
    {
        return TenantBranding::query()->where('tenant_id', $tenantId)->exists();
    }

    public function create(array $data): TenantBranding
    {
        $this->requireValue($data, 'tenant_code');

        return TenantBranding::query()->create($data);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant branding {$key} is required.");
        }
    }

    public function findByTenantId($tenantId) : ?TenantBranding
    {
        return TenantBranding::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function findByTenantIdWithLock($tenantId) : ?TenantBranding
    {
        return TenantBranding::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function firstOrCreateForTenant(int $tenantId, array $data): TenantBranding
    {
        return TenantBranding::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            $data,
        );
    }

    public function update(TenantBranding $tenantBranding, array $data): TenantBranding
    {
        $tenantBranding->update($data);

        return $tenantBranding->refresh();
    }

    public function updateWithLock(TenantBranding $tenantBranding, array $data): TenantBranding
    {
        $lockedBranding = TenantBranding::query()
            ->withoutGlobalScopes()
            ->whereKey($tenantBranding->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedBranding, $data);
    }
}
