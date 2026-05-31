<?php

namespace Database\Seeders;

use App\Models\CoreModule\TenantRole;
use App\Support\TenantPermissionColumns;
use Illuminate\Database\Seeder;

class TenantRoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (config('tenant_permissions.roles', []) as $roleName => $roleConfig) {
            TenantRole::withoutGlobalScopes()->updateOrCreate(
                [
                    'name' => $roleName,
                ],
                [
                    'description' => $roleConfig['description'] ?? null,
                    'is_default' => $roleConfig['is_default'] ?? false,
                    ...TenantPermissionColumns::booleanPayload($roleConfig['permissions'] ?? []),
                ]
            );
        }
    }
}
