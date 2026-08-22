<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'manage_tenant_contact',
        'update_default_currency',
        'update_reporting_currency',
        'update_default_financial_unit',
        'manage_accounting_day_schedule',
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

        DB::table('tenant_roles')
            ->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));

        $managerIds = DB::table('tenant_users')
            ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereIn(DB::raw('LOWER(tenant_roles.name)'), ['owner', 'admin'])
            ->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')
            ->whereIn('tenant_user_id', $managerIds)
            ->update(array_fill_keys(self::PERMISSIONS, true));
    }

    public function down(): void
    {
        foreach (['tenant_user_permissions', 'tenant_roles'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
