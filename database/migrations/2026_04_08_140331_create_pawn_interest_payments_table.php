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
        Schema::create('pawn_interest_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('slip_id')->constrained('pawn_loan_contract_slips')->cascadeOnDelete();
            $table->decimal('payment_amount', 14, 2);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->decimal('calculated_interest', 14, 2)->default(0);
            $table->timestamp('payment_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('start_period_at')->nullable()->index();
            $table->timestamp('end_period_at')->nullable();
            $table->boolean('is_paid')->default(true)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'slip_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pawn_interest_payments');
    }
};
