<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'update_user_roles',
        'update_user_info',
        'update_user_self',
        'assign_permission',
    ];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::NEW_PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });

            DB::table($tableName)->update([
                'update_user_roles' => DB::raw('update_user_admin'),
                'update_user_info' => DB::raw('update_user_all'),
                'update_user_self' => DB::raw('update_user_own'),
                'assign_permission' => DB::raw('(update_user_admin OR assign_admin_permissions)'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            DB::table($tableName)->update([
                'update_user_admin' => DB::raw('update_user_roles'),
                'update_user_all' => DB::raw('update_user_info'),
                'update_user_own' => DB::raw('update_user_self'),
                'assign_admin_permissions' => DB::raw('assign_permission'),
            ]);

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(self::NEW_PERMISSIONS);
            });
        }
    }
};
