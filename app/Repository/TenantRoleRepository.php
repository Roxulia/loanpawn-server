<?php

namespace App\Repository;

use App\Models\CoreModule\TenantRole;

class TenantRoleRepository
{
    public function listAccessible(?int $tenantId)
    {
        return TenantRole::query()
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get();
    }

    public function findDefaultByName(string $roleName): ?TenantRole
    {
        return TenantRole::query()
            ->where('is_default', true)
            ->where('name', $roleName)
            ->first();
    }

    public function findAccessibleByName(?int $tenantId, string $roleName): ?TenantRole
    {
        return TenantRole::query()
            ->where('name', $roleName)
            ->first();
    }

    public function existsAccessible(int $roleId, ?int $tenantId): bool
    {
        return TenantRole::query()
            ->where('id', $roleId)
            ->exists();
    }
}
