<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creation of business loan principal records
        Schema::create('tenant_business_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tbl_tenant_fk')->cascadeOnDelete();
            $table->foreignId('lender_id')->nullable()->constrained('tenant_lenders', indexName: 'tbl_lender_fk')->nullOnDelete();
            $table->foreignId('receipt_account_id')->constrained('financial_accounts', indexName: 'tbl_receipt_acct_fk')->restrictOnDelete();
            $table->string('code');
            $table->integer('update_key')->default(0)->index('tbl_update_idx');
            $table->boolean('is_deleted')->default(false)->index('tbl_deleted_idx');
            $table->decimal('amount', 14, 2);
            $table->decimal('principal_balance', 14, 2);
            $table->boolean('apply_interest')->default(false);
            $table->decimal('interest_rate', 8, 4)->nullable();
            $table->foreignId('interest_type_id')->nullable()->constrained('interest_types', indexName: 'tbl_interest_type_fk')->nullOnDelete();
            $table->timestamp('interest_anchor_at')->nullable();
            $table->timestamp('last_interest_paid_at')->nullable();
            $table->boolean('compound_schedule_enabled')->default(false)->index('tbl_comp_sched_idx');
            $table->unsignedInteger('compound_every')->nullable();
            $table->string('compound_every_type', 20)->nullable();
            $table->timestamp('next_compound_at')->nullable()->index('tbl_next_comp_idx');
            $table->timestamp('last_compounded_at')->nullable();
            $table->text('description');
            $table->string('tag', 120)->nullable();
            $table->boolean('is_paid')->default(false)->index('tbl_paid_idx');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users', indexName: 'tbl_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'tbl_tenant_code_unique');
            $table->index(['tenant_id', 'lender_id', 'is_paid'], 'tbl_tenant_lender_paid_idx');
        });
    }

    public function down(): void
    {
        // Removal of business loan principal records
        Schema::dropIfExists('tenant_business_loans');
    }
};
