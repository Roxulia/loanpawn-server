<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADMIN_MANAGEMENT_PERMISSIONS = [
        'create_admin_user',
        'update_admin_user',
        'delete_admin_user',
        'assign_admin_permissions',
    ];

    private const ADMIN_OPERATIONAL_PERMISSIONS = [
        'dashboard',
        'list_user',
        'create_user',
        'delete_user',
        'update_user_admin',
        'update_user_all',
        'update_user_own',
        'list_customer',
        'create_customer',
        'delete_customer',
        'update_customer',
        'list_collateral',
        'create_collateral',
        'update_collateral',
        'delete_collateral',
        'list_accounting',
        'list_expense',
        'create_expense',
        'update_expense',
        'delete_expense',
        'list_capital',
        'create_capital',
        'update_capital',
        'delete_capital',
        'list_debt',
        'create_debt',
        'update_debt',
        'delete_debt',
        'list_loan_contract',
        'create_loan_contract',
        'delete_loan_contract',
        'manage_slip_document',
    ];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::ADMIN_MANAGEMENT_PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }

        $ownerRoleIds = DB::table('tenant_roles')
            ->whereRaw('LOWER(name) = ?', ['owner'])
            ->pluck('id');
        $adminRoleIds = DB::table('tenant_roles')
            ->whereRaw('LOWER(name) = ?', ['admin'])
            ->pluck('id');

        $ownerPermissions = array_fill_keys(self::ADMIN_MANAGEMENT_PERMISSIONS, true);
        $adminPermissions = [
            'access_all' => false,
            ...array_fill_keys(self::ADMIN_OPERATIONAL_PERMISSIONS, true),
            ...array_fill_keys(self::ADMIN_MANAGEMENT_PERMISSIONS, false),
        ];

        DB::table('tenant_roles')->whereIn('id', $ownerRoleIds)->update($ownerPermissions);
        DB::table('tenant_roles')->whereIn('id', $adminRoleIds)->update($adminPermissions);

        $ownerUserIds = DB::table('tenant_users')->whereIn('role_id', $ownerRoleIds)->pluck('id');
        $adminUserIds = DB::table('tenant_users')->whereIn('role_id', $adminRoleIds)->pluck('id');

        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $ownerUserIds)->update($ownerPermissions);
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminUserIds)->update($adminPermissions);
    }

    public function down(): void
    {
        $adminRoleIds = DB::table('tenant_roles')
            ->whereRaw('LOWER(name) = ?', ['admin'])
            ->pluck('id');
        $adminUserIds = DB::table('tenant_users')->whereIn('role_id', $adminRoleIds)->pluck('id');

        DB::table('tenant_roles')->whereIn('id', $adminRoleIds)->update(['access_all' => true]);
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminUserIds)->update(['access_all' => true]);

        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(self::ADMIN_MANAGEMENT_PERMISSIONS);
            });
        }
    }
};
