<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->string('supplier_payable_code', 32)->nullable()->after('note');
        });

        Schema::create('supplier_payables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('supplier_id')->constrained('purchase_suppliers')->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->unique()->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('original_amount', 15, 2);
            $table->decimal('balance_amount', 15, 2);
            $table->string('status', 16)->default('OPEN');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'supplier_id', 'status']);
        });

        Schema::create('supplier_payable_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('supplier_payable_id')->constrained('supplier_payables')->restrictOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 15, 2);
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'supplier_payable_id', 'paid_at']);
        });

        Schema::create('supplier_payable_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('supplier_payable_id')->constrained('supplier_payables')->restrictOnDelete();
            $table->string('adjustment_type', 24);
            $table->decimal('amount', 15, 2);
            $table->string('source_type', 40);
            $table->string('source_code', 32);
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'adjustment_type', 'source_type', 'source_code'], 'spa_source_unique');
            $table->index(['tenant_id', 'supplier_payable_id']);
        });

        Schema::create('supplier_credits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('supplier_id')->constrained('purchase_suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('source_return_item_code', 32);
            $table->decimal('original_amount', 15, 2);
            $table->decimal('remaining_amount', 15, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'source_return_item_code']);
            $table->index(['tenant_id', 'supplier_id', 'currency_id']);
        });

        Schema::create('supplier_credit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('supplier_credit_id')->constrained('supplier_credits')->restrictOnDelete();
            $table->foreignId('supplier_payable_id')->constrained('supplier_payables')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->foreignId('asset_accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'supplier_credit_id', 'supplier_payable_id'], 'sca_credit_payable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_allocations');
        Schema::dropIfExists('supplier_credits');
        Schema::dropIfExists('supplier_payable_adjustments');
        Schema::dropIfExists('supplier_payable_payments');
        Schema::dropIfExists('supplier_payables');
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn('supplier_payable_code');
        });
    }
};