<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('source_type', 32); $table->string('source_code', 32); $table->decimal('quantity', 15, 3);
            $table->decimal('remaining_quantity', 15, 3); $table->json('inventory_unit_codes')->nullable();
            $table->string('status', 16)->default('ACTIVE'); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'inv_res_tenant_code_uq');
            $table->index(['tenant_id', 'inventory_item_id', 'inventory_location_id', 'status'], 'inv_res_item_loc_status_ix');
            $table->index(['tenant_id', 'source_type', 'source_code'], 'inv_res_source_ix');
        });
        Schema::table('ownership_movements', function (Blueprint $table): void {
            $table->decimal('reserved_delta', 15, 3)->default(0)->after('pledged_delta');
        });
    }

    public function down(): void
    {
        Schema::table('ownership_movements', fn (Blueprint $table) => $table->dropColumn('reserved_delta'));
        Schema::dropIfExists('inventory_reservations');
    }
};
