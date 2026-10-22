<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve tenant-scoped ownership monthly checkpoint balances with exact quantities and restrictive history references.
        Schema::create('ownership_monthly_checkpoint_balances', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('ownership_monthly_checkpoint_id');
            $table->foreign('ownership_monthly_checkpoint_id', 'own_checkpoint_balance_parent_fk')
                ->references('id')->on('ownership_monthly_checkpoints')->cascadeOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->decimal('owned_quantity', 24, 3);
            $table->decimal('reserved_quantity', 24, 3);
            $table->decimal('pledged_quantity', 24, 3);
            $table->unique(['ownership_monthly_checkpoint_id', 'acquisition_lot_id'], 'own_checkpoint_lot_uq');
        });
    }

    public function down(): void
    {
        // Remove derived closing balances without changing any retained source movements.
        Schema::dropIfExists('ownership_monthly_checkpoint_balances');
    }
};
