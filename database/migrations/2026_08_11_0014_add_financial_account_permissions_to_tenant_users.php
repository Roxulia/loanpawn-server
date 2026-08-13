<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['list_financial_account', 'create_financial_account', 'update_financial_account', 'delete_financial_account'];

    public function up(): void
    {
        Schema::table('tenant_user_permissions', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });
        $adminIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])->pluck('tenant_users.id');
        $userIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $userIds)->update(['list_financial_account' => true]);
    }

    public function down(): void
    {
        Schema::table('tenant_user_permissions', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
