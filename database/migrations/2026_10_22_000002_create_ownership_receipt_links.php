<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve tenant-scoped ownership receipt links with exact quantities and restrictive history references.
        Schema::create('ownership_receipt_links', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->foreignId('purchase_receipt_line_id')->constrained('purchase_receipt_lines')->restrictOnDelete();
            $table->string('code', 40);
            $table->decimal('linked_quantity', 14, 3);
            $table->decimal('remaining_owned_quantity', 14, 3);
            $table->unsignedBigInteger('idempotency_record_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'own_link_code_uq');
            $table->unique(['tenant_id', 'idempotency_record_id'], 'own_link_replay_uq');
            $table->index(['tenant_id', 'purchase_receipt_line_id', 'id'], 'own_link_receipt_idx');
            $table->index(['tenant_id', 'acquisition_lot_id', 'id'], 'own_link_lot_idx');
        });
    }

    public function down(): void
    {
        // Remove links only when explicitly rolling back this schema; source purchases and movements remain.
        Schema::dropIfExists('ownership_receipt_links');
    }
};
