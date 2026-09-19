<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creation of business loan interest periods
        Schema::create('tenant_business_loan_interest_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tbla_tenant_fk')->cascadeOnDelete();
            $table->foreignId('business_loan_id')->constrained('tenant_business_loans', indexName: 'tbla_loan_fk')->cascadeOnDelete();
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('calculated_interest', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('compounded_amount', 14, 2)->default(0);
            $table->timestamp('compounded_at')->nullable();
            $table->timestamp('start_period_at')->index('tbla_start_idx');
            $table->timestamp('end_period_at');
            $table->string('period_timezone', 64)->nullable();
            $table->boolean('is_paid')->default(false)->index('tbla_paid_idx');
            $table->timestamps();
            $table->unique(['tenant_id', 'business_loan_id', 'start_period_at'], 'tbla_period_unique');
        });
    }

    public function down(): void
    {
        // Removal of business loan interest periods
        Schema::dropIfExists('tenant_business_loan_interest_accruals');
    }
};
