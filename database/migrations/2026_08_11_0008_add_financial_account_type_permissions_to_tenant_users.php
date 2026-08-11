<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['list_financial_account_type', 'create_financial_account_type', 'update_financial_account_type', 'delete_financial_account_type'];

    public function up(): void
    {
        Schema::table('tenant_user_permissions', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });
        $admins = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])->pluck('tenant_users.id');
        $users = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $admins)->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $users)->update(['list_financial_account_type' => true]);
    }

    public function down(): void
    {
        Schema::table('tenant_user_permissions', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
