<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope_key', 30)->default('platform');
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('symbol', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'is_active', 'name']);
            $table->unique(['scope_key', 'code']);
        });

        Schema::create('catalog_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('update_key')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'name']);
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('description', 255)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->nullOnDelete();
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('tracking_mode', 20)->default('QUANTITY');
            $table->foreignId('unit_id')->constrained('catalog_units')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('update_key')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'category_id', 'is_active']);
        });

        DB::table('catalog_units')->updateOrInsert(
            ['tenant_id' => null, 'code' => 'unit'],
            ['scope_key' => 'platform', 'name' => 'Unit', 'symbol' => null, 'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_categories');
        Schema::dropIfExists('catalog_units');
    }
};
