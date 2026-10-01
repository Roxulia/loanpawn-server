<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['list_catalog_item', 'create_catalog_item', 'update_catalog_item', 'manage_catalog_taxonomy'];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }
        DB::table('tenant_roles')->whereRaw('LOWER(name) IN (?, ?)', ['owner', 'admin'])->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['user'])->update(['list_catalog_item' => true]);
        $adminIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) IN (?, ?)', ['owner', 'admin'])->pluck('tenant_users.id');
        $userIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $userIds)->update(['list_catalog_item' => true]);
    }

    public function down(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
