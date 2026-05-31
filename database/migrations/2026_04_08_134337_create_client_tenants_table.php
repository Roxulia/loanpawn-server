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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('tenant_code', 32)->unique();
            $table->string('subdomain', 63)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('tenant_licenses', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('license_key', 16)->unique()->index();
            $table->enum('plan_type', ['trial','basic', 'premium'])->index();
            $table->enum('status', ['pending_activation', 'active', 'expired', 'suspended', 'cancelled'])
                ->default('pending_activation')
                ->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_licenses');
        Schema::dropIfExists('tenants');
    }
};
