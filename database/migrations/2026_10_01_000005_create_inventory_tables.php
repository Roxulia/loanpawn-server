<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public location references use generated tenant business codes.
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('type', 20)->default('SHOP');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active', 'name']);
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public Inventory item references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('catalog_units')->restrictOnDelete();
            $table->string('tracking_mode', 20);
            $table->string('name', 180);
            $table->string('description', 255)->nullable();
            $table->decimal('quantity_scale', 14, 3)->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'catalog_item_id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('inventory_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public serialized-unit references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('identifier', 120)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'inventory_item_id']);
            $table->unique(['tenant_id', 'identifier']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Public movement references use generated tenant business codes.
            $table->string('code', 40);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('inventory_unit_id')->nullable()->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('movement_type', 20);
            $table->string('reason', 255)->nullable();
            $table->string('source_type', 120)->nullable();
            // Cross-module references are exposed using a business code.
            $table->string('source_code', 120)->nullable();
            $table->unsignedBigInteger('idempotency_record_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'inventory_item_id', 'occurred_at']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'from_location_id', 'to_location_id']);
            $table->index(['tenant_id', 'source_type', 'source_code']);
        });

        // Give each existing tenant a usable default location with a generated business code.
        $codeGenerator = app(TableIdGenerationService::class);
        DB::table('tenants')->select('id')->orderBy('id')->get()->each(function (object $tenant) use ($codeGenerator): void {
            DB::table('inventory_locations')->insertOrIgnore([
                'tenant_id' => $tenant->id,
                'code' => $codeGenerator->generateForTenant((int) $tenant->id, 'inventory_locations', CarbonImmutable::now()),
                'name' => 'Main Shop',
                'type' => 'SHOP',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_units');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_locations');
    }
};
