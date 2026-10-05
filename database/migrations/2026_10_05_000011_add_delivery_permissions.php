<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['list_delivery', 'manage_delivery', 'manage_delivery_fee'];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) $table->boolean($permission)->default(false);
            });
        }
        $adminPermissions = array_fill_keys(self::PERMISSIONS, true);
        DB::table('tenant_roles')->whereRaw('LOWER(name) IN (?, ?)', ['owner', 'admin'])->update($adminPermissions);
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['user'])->update(['list_delivery' => true]);
        $admins = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereRaw('LOWER(tenant_roles.name) IN (?, ?)', ['owner', 'admin'])->pluck('tenant_users.id');
        $users = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $admins)->update($adminPermissions);
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $users)->update(['list_delivery' => true]);
    }

    public function down(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
