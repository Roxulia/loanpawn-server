<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_business_loan',
        'create_business_loan',
        'update_business_loan',
        'delete_business_loan',
        'list_lender',
        'create_lender',
        'update_lender',
        'delete_lender',
    ];

    public function up(): void
    {
        // Addition of assignable permissions for both resources
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }

        // Enabling permissions for manager roles
        DB::table('tenant_roles')
            ->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));

        // Enabling permissions for users assigned to manager roles
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
        // Removal of assignable permissions for both resources
        foreach (['tenant_user_permissions', 'tenant_roles'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
