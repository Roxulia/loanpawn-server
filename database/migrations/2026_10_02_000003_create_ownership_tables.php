<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store tenant ownership aggregates separately from physical Inventory items.
        Schema::create('owned_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public ownership references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('lifecycle_status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'inventory_item_id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'lifecycle_status', 'id']);
        });

        // Preserve acquisition details and cost/value currency independently for each lot.
        Schema::create('acquisition_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public acquisition-lot references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->string('source_module', 40)->nullable();
            $table->string('source_type', 120)->nullable();
            // Store references to source records by their public business code.
            $table->string('source_code', 120)->nullable();
            $table->date('acquired_at');
            $table->decimal('acquired_quantity', 14, 3);
            $table->decimal('unit_cost_basis', 18, 4);
            $table->decimal('estimated_unit_value', 18, 4)->nullable();
            $table->string('currency_code', 10);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'owned_item_id', 'acquired_at']);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'source_module', 'source_type', 'source_code'], 'ownership_lot_source_code_unique');
        });

        // Record signed ownership and pledged deltas; balances are derived from this append-only ledger.
        Schema::create('ownership_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public ownership-ledger references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->nullable()->constrained('acquisition_lots')->restrictOnDelete();
            $table->string('movement_type', 24);
            $table->decimal('quantity', 14, 3);
            $table->decimal('owned_delta', 14, 3)->default(0);
            $table->decimal('pledged_delta', 14, 3)->default(0);
            $table->string('source_module', 40)->nullable();
            $table->string('source_type', 120)->nullable();
            // Store references to source records by their public business code.
            $table->string('source_code', 120)->nullable();
            $table->unsignedBigInteger('idempotency_record_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'owned_item_id', 'occurred_at']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'acquisition_lot_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_module', 'source_type', 'source_code']);
            $table->unique(['tenant_id', 'idempotency_record_id']);
        });
    }

    public function down(): void
    {
        // Drop dependent ledger and lot tables before their ownership parent.
        Schema::dropIfExists('ownership_movements');
        Schema::dropIfExists('acquisition_lots');
        Schema::dropIfExists('owned_items');
    }
};
