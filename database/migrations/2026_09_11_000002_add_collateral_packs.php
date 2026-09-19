<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the rate used to value collateral when it is edited later.
        Schema::table('pawn_collateral_items', function (Blueprint $table) {
            $table->decimal('material_price_per_kyat', 14, 2)->nullable();
        });
        Schema::create('pawn_collateral_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pawn_collateral_item_id')->constrained('pawn_collateral_items')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('quantity')->default(1);
            foreach (['kyat', 'pal', 'yway'] as $weight) {
                $table->decimal($weight, 8, 2)->nullable();
            }
            $table->foreignId('material_type_id')->nullable()->constrained('material_types')->nullOnDelete();
            $table->string('image_url')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'pawn_collateral_item_id'],'pack_item_tenant_collateral_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pawn_collateral_pack_items');
        Schema::table('pawn_collateral_items', fn (Blueprint $table) => $table->dropColumn('material_price_per_kyat'));
    }
};
