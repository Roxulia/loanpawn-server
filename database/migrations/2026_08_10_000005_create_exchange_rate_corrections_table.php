<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_entry_id')->constrained('exchange_rate_entries')->restrictOnDelete();
            $table->foreignId('replacement_entry_id')->nullable()->constrained('exchange_rate_entries')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->string('action', 20);
            $table->text('reason');
            $table->foreignId('corrected_by_tenant_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('corrected_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope_key', 'original_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_corrections');
    }
};
