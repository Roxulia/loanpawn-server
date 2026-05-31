<?php

namespace App\Repository;

use App\Models\CoreModule\TenantUser;
use App\Models\CoreModule\TenantUserPermission;

class TenantUserPermissionRepository
{
    public function findForUser(TenantUser $tenantUser): ?TenantUserPermission
    {
        return TenantUserPermission::query()
            ->where('tenant_id', $tenantUser->tenant_id)
            ->where('tenant_user_id', $tenantUser->id)
            ->first();
    }

    public function createForUser(TenantUser $tenantUser, array $permissions): TenantUserPermission
    {
        return TenantUserPermission::query()->create([
            'tenant_id' => $tenantUser->tenant_id,
            'tenant_user_id' => $tenantUser->id,
            ...$permissions,
        ]);
    }

    public function updateOrCreateForUser(TenantUser $tenantUser, array $permissions): TenantUserPermission
    {
        return TenantUserPermission::query()->updateOrCreate(
            [
                'tenant_id' => $tenantUser->tenant_id,
                'tenant_user_id' => $tenantUser->id,
            ],
            $permissions
        );
    }
}
