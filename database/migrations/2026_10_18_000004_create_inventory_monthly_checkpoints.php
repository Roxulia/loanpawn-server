<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Publish one completed, tenant-local month checkpoint per Inventory item.
        Schema::create('inventory_monthly_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->date('month');
            $table->string('timezone', 64);
            $table->dateTime('cutoff_at');
            $table->unsignedBigInteger('movement_watermark')->default(0);
            $table->dateTime('completed_at');
            $table->timestamps();
            $table->unique([
                'tenant_id',
                'inventory_item_id',
                'month'
                ], 'inv_checkpoint_item_month_uq');
        });

        // Keep exact closing quantities per location, including exhausted locations.
        Schema::create('inventory_monthly_checkpoint_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_monthly_checkpoint_id');
            $table->unsignedBigInteger('inventory_location_id');
            $table->foreign('inventory_monthly_checkpoint_id', 'inv_checkpoint_balance_parent_fk')
                ->references('id')->on('inventory_monthly_checkpoints')->cascadeOnDelete();
            $table->foreign('inventory_location_id', 'inv_checkpoint_balance_location_fk')
                ->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 24, 3);
            $table->unique([
                'inventory_monthly_checkpoint_id',
                'inventory_location_id'
                ], 'inv_checkpoint_location_uq');
        });

        // Locate only uncovered movements after a checkpoint instead of scanning all history.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index([
                'tenant_id',
                'inventory_item_id',
                'created_at',
                'id'
                ], 'inv_mov_item_recorded_idx');
            $table->index([
                'tenant_id',
                'inventory_item_id',
                'id'
                ], 'inv_mov_item_checkpoint_tail_idx');
        });
    }

    public function down(): void
    {
        // Remove derived data before its parent and leave source movements intact.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inv_mov_item_recorded_idx');
            $table->dropIndex('inv_mov_item_checkpoint_tail_idx');
        });
        Schema::dropIfExists('inventory_monthly_checkpoint_balances');
        Schema::dropIfExists('inventory_monthly_checkpoints');
    }
};
