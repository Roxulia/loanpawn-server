<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support latest serialized-item custody and item/location balance queries.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index([
                'tenant_id',
                'inventory_serialized_item_id',
                'id'
                ], 'inv_mov_serialized_item_latest_idx');
            $table->index([
                'tenant_id',
                'inventory_item_id',
                'to_location_id'
                ], 'inv_mov_item_to_loc_idx');
            $table->index([
                'tenant_id',
                'inventory_item_id',
                'from_location_id'
                ], 'inv_mov_item_from_loc_idx');
        });
    }

    public function down(): void
    {
        // Remove only this migration's indexes, retaining existing indexes and constraints.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inv_mov_serialized_item_latest_idx');
            $table->dropIndex('inv_mov_item_to_loc_idx');
            $table->dropIndex('inv_mov_item_from_loc_idx');
        });
    }
};
