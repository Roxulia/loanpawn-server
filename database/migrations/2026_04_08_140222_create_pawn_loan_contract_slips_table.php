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
        Schema::create('pawn_loan_contract_slips', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('slip_no', 60);
            $table->foreignId('customer_id')->constrained('tenant_customers')->restrictOnDelete();
            $table->decimal('loan_amount', 14, 2);
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->foreignId('interest_type_id')->nullable()->constrained('interest_types')->nullOnDelete();
            $table->timestamp('expire_at')->nullable()->index();
            $table->timestamp('last_interest_added_at')->nullable();
            $table->timestamp('last_interest_paid_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->unsignedInteger('expiry_quota')->default(0);
            $table->string('expiry_quota_type', 20)->default('day');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slip_no']);
            $table->index(['tenant_id', 'slip_no']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['is_deleted', 'status', 'expire_at'], 'pawn_slips_expire_at_check_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pawn_loan_contract_slips');
    }
};
