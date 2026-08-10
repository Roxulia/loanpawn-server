<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->string('code', 12);
            $table->string('name', 120);
            $table->string('symbol', 12)->nullable();
            $table->unsignedTinyInteger('decimal_precision')->default(2);
            $table->string('rounding_mode', 20)->default('HALF_UP');
            $table->decimal('adjustment_step', 20, 8)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('update_key')->default(0);
            $table->foreignId('created_by_tenant_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope_key', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
