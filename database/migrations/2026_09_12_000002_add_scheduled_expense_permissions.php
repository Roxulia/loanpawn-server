<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_scheduled_expense',
        'create_scheduled_expense',
        'update_scheduled_expense',
        'delete_scheduled_expense',
    ];

    public function up(): void
    {
        // Permissions exist on both roles and per-user overrides in the current
        // authorization model, so both tables must evolve together.
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }

        // Preserve manager access for existing tenants during deployment.
        DB::table('tenant_roles')
            ->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));

        $managerUserIds = DB::table('tenant_users')
            ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereIn(DB::raw('LOWER(tenant_roles.name)'), ['owner', 'admin'])
            ->pluck('tenant_users.id');

        DB::table('tenant_user_permissions')
            ->whereIn('tenant_user_id', $managerUserIds)
            ->update(array_fill_keys(self::PERMISSIONS, true));
    }

    public function down(): void
    {
        foreach (['tenant_user_permissions', 'tenant_roles'] as $tableName) {
            Schema::table(
                $tableName,
                fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS),
            );
        }
    }
};
