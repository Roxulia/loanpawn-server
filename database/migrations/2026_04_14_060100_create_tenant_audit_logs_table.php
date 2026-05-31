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
        Schema::create('tenant_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_code');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('action', 120);
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->foreign('tenant_code')->references('tenant_code')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_code','created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_audit_logs');
    }
};
