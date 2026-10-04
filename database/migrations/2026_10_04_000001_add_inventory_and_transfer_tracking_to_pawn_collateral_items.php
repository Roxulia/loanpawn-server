<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pawn_collateral_items', function (Blueprint $table): void {
            // Keep the physical custody item linked to its originating collateral record.
            $table->foreignId('inventory_item_id')->nullable()->after('loan_contract_id')
                ->constrained('inventory_items')->restrictOnDelete();
            $table->timestamp('ownership_transferred_at')->nullable()->after('inventory_item_id');
            $table->unique(['tenant_id', 'inventory_item_id'], 'pawn_collateral_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pawn_collateral_items', function (Blueprint $table): void {
            $table->dropUnique('pawn_collateral_inventory_unique');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('ownership_transferred_at');
        });
    }
};
