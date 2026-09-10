<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creation of business loan payments
        Schema::create('tenant_business_loan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tblp_tenant_fk')->cascadeOnDelete();
            $table->foreignId('business_loan_id')->constrained('tenant_business_loans', indexName: 'tblp_loan_fk')->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained('financial_accounts', indexName: 'tblp_payment_acct_fk')->restrictOnDelete();
            $table->string('code');
            $table->enum('allocation_order', ['interest_first', 'principal_first'])->default('interest_first');
            $table->decimal('payment_amount', 14, 2);
            $table->decimal('principal_paid', 14, 2)->default(0);
            $table->decimal('interest_paid', 14, 2)->default(0);
            $table->timestamp('payment_at');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users', indexName: 'tblp_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'tblp_tenant_code_unique');
        });

        // Creation of business loan payment interest allocations
        Schema::create('tenant_business_loan_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tblpa_tenant_fk')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('tenant_business_loan_payments', indexName: 'tblpa_payment_fk')->cascadeOnDelete();
            $table->foreignId('accrual_id')->constrained('tenant_business_loan_interest_accruals', indexName: 'tblpa_accrual_fk')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'accrual_id'], 'tblpa_payment_accrual_unique');
        });
    }

    public function down(): void
    {
        // Removal of business loan payment interest allocations
        Schema::dropIfExists('tenant_business_loan_payment_allocations');

        // Removal of business loan payments
        Schema::dropIfExists('tenant_business_loan_payments');
    }
};
