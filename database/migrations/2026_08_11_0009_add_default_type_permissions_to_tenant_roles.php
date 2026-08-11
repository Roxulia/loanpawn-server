<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_material_type',
        'create_material_type',
        'update_material_type',
        'delete_material_type',
        'list_interest_type',
        'create_interest_type',
        'update_interest_type',
        'delete_interest_type',
        'list_item_category_type',
        'create_item_category_type',
        'update_item_category_type',
        'delete_item_category_type',
        'list_expense_type',
        'create_expense_type',
        'update_expense_type',
        'delete_expense_type',
    ];

    private const LIST_PERMISSIONS = [
        'list_material_type',
        'list_interest_type',
        'list_item_category_type',
        'list_expense_type',
    ];

    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                $table->boolean($permission)->default(false);
            }
        });

        DB::table('tenant_roles')
            ->whereRaw('LOWER(name) = ?', ['admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));
        DB::table('tenant_roles')
            ->whereRaw('LOWER(name) = ?', ['user'])
            ->update(array_fill_keys(self::LIST_PERMISSIONS, true));
    }

    public function down(): void
    {
        Schema::table('tenant_roles', fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
    }
};
