<?php

namespace App\Repository;

use App\Models\CoreModule\TenantContact;
use App\Exceptions\RequiredValueMissing;

class TenantContactRepository
{
    public function create(array $data): TenantContact
    {
        $this->requireValue($data, 'tenant_code');

        return TenantContact::query()->create($data);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant contact {$key} is required.");
        }
    }

    public function findByTenantId(int $tenantId): ?TenantContact
    {
        return TenantContact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function firstOrCreateForTenant(int $tenantId, array $data): TenantContact
    {
        return TenantContact::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            $data,
        );
    }

    public function update(TenantContact $tenantContact, array $data): TenantContact
    {
        $tenantContact->update($data);

        return $tenantContact->refresh();
    }
}
