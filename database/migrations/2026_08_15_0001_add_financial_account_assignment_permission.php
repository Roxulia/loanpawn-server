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
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('manage_financial_account_assignments')->default(false);
            });
        }

        $managerRoleIds = DB::table('tenant_roles')
            ->whereRaw('LOWER(name) IN (?, ?)', ['owner', 'admin'])
            ->pluck('id');
        DB::table('tenant_roles')->whereIn('id', $managerRoleIds)->update(['manage_financial_account_assignments' => true]);

        $managerUserIds = DB::table('tenant_users')->whereIn('role_id', $managerRoleIds)->pluck('id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $managerUserIds)->update(['manage_financial_account_assignments' => true]);
    }

    public function down(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('manage_financial_account_assignments'));
        }
    }
};
