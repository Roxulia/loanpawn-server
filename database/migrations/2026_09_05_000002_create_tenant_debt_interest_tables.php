<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_debt_interest_accruals', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained('tenant_debts')->cascadeOnDelete();
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('calculated_interest', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestamp('start_period_at')->index();
            $table->timestamp('end_period_at');
            $table->boolean('is_paid')->default(false)->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'debt_id', 'start_period_at'], 'debt_interest_period_unique');
        });

        Schema::create('tenant_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained('tenant_debts')->cascadeOnDelete();
            $table->foreignId('accept_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->enum('allocation_order', ['interest_first', 'principal_first'])->default('interest_first');
            $table->decimal('payment_amount', 14, 2);
            $table->decimal('principal_paid', 14, 2)->default(0);
            $table->decimal('interest_paid', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->timestamp('payment_at');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'debt_id']);
        });

        Schema::create('tenant_debt_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('tenant_debt_payments')->cascadeOnDelete();
            $table->foreignId('accrual_id')->constrained('tenant_debt_interest_accruals')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'accrual_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_debt_payment_allocations');
        Schema::dropIfExists('tenant_debt_payments');
        Schema::dropIfExists('tenant_debt_interest_accruals');
    }
};
