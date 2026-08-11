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
        Schema::table('tenant_roles', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['admin'])->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['user'])->update(['list_financial_account' => true]);
    }

    public function down(): void
    {
        Schema::table('tenant_roles', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
