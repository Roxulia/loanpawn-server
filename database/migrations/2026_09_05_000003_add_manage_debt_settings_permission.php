<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->boolean('manage_debt_settings')->default(false));
        }

        DB::table('tenant_roles')
            ->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])
            ->update(['manage_debt_settings' => true]);

        $managerIds = DB::table('tenant_users')
            ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereIn(DB::raw('LOWER(tenant_roles.name)'), ['owner', 'admin'])
            ->pluck('tenant_users.id');

        DB::table('tenant_user_permissions')
            ->whereIn('tenant_user_id', $managerIds)
            ->update(['manage_debt_settings' => true]);
    }

    public function down(): void
    {
        foreach (['tenant_user_permissions', 'tenant_roles'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('manage_debt_settings'));
        }
    }
};
