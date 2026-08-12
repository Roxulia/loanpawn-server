<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_roles', fn (Blueprint $table) => $table->boolean('transfer_financial_account')->default(false));
        Schema::table('tenant_user_permissions', fn (Blueprint $table) => $table->boolean('transfer_financial_account')->default(false));
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['admin'])->update(['transfer_financial_account' => true]);
        $adminIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)->update(['transfer_financial_account' => true]);
    }

    public function down(): void
    {
        Schema::table('tenant_user_permissions', fn (Blueprint $table) => $table->dropColumn('transfer_financial_account'));
        Schema::table('tenant_roles', fn (Blueprint $table) => $table->dropColumn('transfer_financial_account'));
    }
};
