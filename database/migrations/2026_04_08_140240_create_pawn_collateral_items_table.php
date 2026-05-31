<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pawn_collateral_items', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('loan_contract_id')->nullable()->constrained('pawn_loan_contract_slips')->nullOnDelete();
            $table->string('type', 20)->index();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('brand_name', 80)->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('estimated_value', 14, 2)->default(0);
            $table->foreignId('material_type_id')->nullable()->constrained('material_types')->nullOnDelete();
            $table->decimal('kyat', 8, 2)->default(0);
            $table->decimal('pal', 8, 2)->default(0);
            $table->decimal('yway', 8, 2)->default(0);
            $table->string('item_status', 30)->default('active')->index();
            $table->boolean('contains_gemstones')->default(false);
            $table->json('gemstone_details')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('minimum_retail_price', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id','code']);
            $table->index(['tenant_id', 'loan_contract_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pawn_collateral_items');
    }
};
