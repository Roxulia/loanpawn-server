<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support bounded ownership_movements reads; existing source and lot-time indexes remain.
        Schema::table('ownership_movements', function (Blueprint $table): void {
            $table->index(['tenant_id', 'owned_item_id', 'created_at', 'id'], 'own_mov_recorded_idx');
            $table->index(['tenant_id', 'owned_item_id', 'id'], 'own_mov_tail_idx');
        });
    }

    public function down(): void
    {
        // Remove only the indexes added by this migration.
        Schema::table('ownership_movements', function (Blueprint $table): void {
            $table->dropIndex('own_mov_tail_idx');
            $table->dropIndex('own_mov_recorded_idx');
        });
    }
};
