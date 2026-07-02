<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pawn_collateral_items', function (Blueprint $table) {
            $table->foreignId('item_category_type_id')
                ->nullable()
                ->after('material_type_id')
                ->constrained('item_category_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pawn_collateral_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_type_id');
        });
    }
};
