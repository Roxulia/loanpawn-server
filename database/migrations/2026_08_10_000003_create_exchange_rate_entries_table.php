<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_entries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->foreignId('exchange_rate_pair_id')->constrained('exchange_rate_pairs')->restrictOnDelete();
            $table->decimal('rate', 28, 12);
            $table->date('effective_date');
            $table->timestamp('observed_at');
            $table->string('source', 20);
            $table->string('idempotency_key', 120)->nullable();
            $table->boolean('is_void')->default(false)->index();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by_tenant_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignId('voided_by_tenant_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('voided_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'idempotency_key']);
            $table->index(['scope_key', 'exchange_rate_pair_id', 'effective_date'], 'rate_entry_scope_pair_date_index');
            $table->index(['exchange_rate_pair_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_entries');
    }
};
