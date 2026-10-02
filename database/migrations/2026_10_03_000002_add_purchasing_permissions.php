<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_supplier', 'manage_supplier', 'list_purchase_order', 'manage_purchase_order',
        'manage_purchase_payment', 'manage_purchase_receipt', 'manage_purchase_return',
    ];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }
        DB::table('tenant_roles')->whereRaw('LOWER(name) IN (?, ?)', ['owner', 'admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['user'])
            ->update(['list_supplier' => true, 'list_purchase_order' => true]);
        $adminIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereRaw('LOWER(tenant_roles.name) IN (?, ?)', ['owner', 'admin'])->pluck('tenant_users.id');
        $userIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)
            ->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $userIds)
            ->update(['list_supplier' => true, 'list_purchase_order' => true]);
    }

    public function down(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
