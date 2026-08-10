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
        Schema::table('tenant_roles', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });

        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['admin'])->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['user'])->update([
            'list_currency' => true,
            'list_exchange_pair' => true,
            'list_exchange_rate' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('tenant_roles', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
