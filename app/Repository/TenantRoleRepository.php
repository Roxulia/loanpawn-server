<?php

namespace App\Repository;

use App\Models\CoreModule\TenantRole;

class TenantRoleRepository
{
    public function listAccessible(?int $tenantId, bool $excludeOwner = false)
    {
        $query = TenantRole::query()
            ->where('is_deleted', false)
            ->orderBy('name');

        if ($excludeOwner) {
            $query->whereRaw('LOWER(name) NOT LIKE ?', ['%owner%']);
        }

        return $query->get();
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

    public function findAccessibleById(int $roleId, ?int $tenantId): ?TenantRole
    {
        return TenantRole::query()
            ->where('id', $roleId)
            ->where('is_deleted', false)
            ->first();
    }
}
