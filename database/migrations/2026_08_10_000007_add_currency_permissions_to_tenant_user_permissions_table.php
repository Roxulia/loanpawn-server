<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_currency', 'create_currency', 'update_currency', 'delete_currency',
        'list_exchange_pair', 'create_exchange_pair', 'update_exchange_pair', 'delete_exchange_pair',
        'list_exchange_rate', 'create_exchange_rate', 'correct_exchange_rate', 'void_exchange_rate',
    ];

    public function up(): void
    {
        Schema::table('tenant_user_permissions', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });

        $adminUserIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])->pluck('tenant_users.id');
        $userIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['user'])->pluck('tenant_users.id');

        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminUserIds)->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $userIds)->update([
            'list_currency' => true,
            'list_exchange_pair' => true,
            'list_exchange_rate' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('tenant_user_permissions', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
