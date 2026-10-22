<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve tenant-scoped ownership piece memberships with exact quantities and restrictive history references.
        Schema::create('ownership_piece_memberships', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->foreignId('ownership_receipt_link_id')->constrained('ownership_receipt_links')->restrictOnDelete();
            $table->foreignId('inventory_serialized_item_id')->constrained('inventory_serialized_items')->restrictOnDelete();
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            // MySQL permits many historical NULL keys but only one current episode per piece.
            $table->unsignedBigInteger('current_piece_id')
                ->nullable()->storedAs('CASE WHEN ended_at IS NULL THEN inventory_serialized_item_id ELSE NULL END');
            $table->timestamps();
            $table->unique('current_piece_id', 'own_piece_current_uq');
            $table->index(['tenant_id', 'acquisition_lot_id', 'ended_at', 'id'], 'own_piece_lot_idx');
            $table->index(['tenant_id', 'inventory_serialized_item_id', 'id'], 'own_piece_history_idx');
        });
    }

    public function down(): void
    {
        // Drop episode history only during an explicit schema rollback; referenced Inventory pieces remain.
        Schema::dropIfExists('ownership_piece_memberships');
    }
};
