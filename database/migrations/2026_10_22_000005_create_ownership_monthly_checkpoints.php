<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve tenant-scoped ownership monthly checkpoints with exact quantities and restrictive history references.
        Schema::create('ownership_monthly_checkpoints', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->date('month');
            $table->string('timezone', 64);
            $table->dateTime('cutoff_at', 6);
            $table->unsignedBigInteger('movement_watermark')->default(0);
            $table->dateTime('completed_at', 6);
            $table->decimal('owned_quantity', 24, 3);
            $table->decimal('reserved_quantity', 24, 3);
            $table->decimal('pledged_quantity', 24, 3);
            $table->timestamps();
            $table->unique(['tenant_id', 'owned_item_id', 'month'], 'own_checkpoint_month_uq');
        });
    }

    public function down(): void
    {
        // Remove derived headers only after rolling back their child balances.
        Schema::dropIfExists('ownership_monthly_checkpoints');
    }
};
