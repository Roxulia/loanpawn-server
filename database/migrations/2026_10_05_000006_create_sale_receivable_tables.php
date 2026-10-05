<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Create order-linked, interest-ready customer receivable records.
    public function up(): void
    {
        Schema::create('sale_receivables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('sales_delivery_id')->nullable()->constrained('sales_deliveries')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('legacy_tenant_debt_id')->nullable()->constrained('tenant_debts')->nullOnDelete();
            $table->decimal('original_amount', 15, 2);
            $table->decimal('balance_amount', 15, 2);
            $table->boolean('apply_interest')->default(false);
            $table->decimal('interest_rate', 8, 4)->nullable();
            $table->foreignId('interest_type_id')->nullable()->constrained('interest_types')->nullOnDelete();
            $table->string('status', 16)->default('OPEN');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ar_tenant_code_uq');
            $table->unique('sales_delivery_id', 'sale_ar_delivery_uq');
            $table->unique('legacy_tenant_debt_id', 'sale_ar_legacy_debt_uq');
            $table->index(['tenant_id', 'customer_id', 'status'], 'sale_ar_customer_status_ix');
            $table->index(['tenant_id', 'sales_order_id'], 'sale_ar_order_ix');
        });

        Schema::create('sale_receivable_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('sale_receivable_id')->constrained('sale_receivables')->restrictOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 15, 2);
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ar_pay_tenant_code_uq');
            $table->index(['tenant_id', 'sale_receivable_id', 'paid_at'], 'sale_ar_pay_receivable_date_ix');
        });

        Schema::create('sale_receivable_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->foreignId('sale_receivable_id')->constrained('sale_receivables')->restrictOnDelete();
            $table->string('adjustment_type', 24);
            $table->decimal('amount', 15, 2);
            $table->string('source_type', 40);
            $table->string('source_code', 32);
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ar_adj_tenant_code_uq');
            $table->unique(['tenant_id', 'adjustment_type', 'source_type', 'source_code'], 'sale_ar_adj_source_uq');
            $table->index(['tenant_id', 'sale_receivable_id'], 'sale_ar_adj_receivable_ix');
        });

        // Copy legacy sale balances so they stop being active Tenant Debt records.
        DB::table('tenant_debts')->where('source_type', 'SALE')->orderBy('id')->each(function (object $debt): void {
            $order = DB::table('sales_orders')->where('tenant_id', $debt->tenant_id)->where('code', $debt->source_code)->first();
            // Resolve tenant-specific currency first, matching CurrencyRepository::findVisible.
            $currency = DB::table('currencies')->where('code', $debt->currency_code)
                ->where('tenant_id', $debt->tenant_id)->first()
                ?? DB::table('currencies')->where('code', $debt->currency_code)->whereNull('tenant_id')->first();
            if ($order === null || $currency === null) return;
            $receivableId = DB::table('sale_receivables')->insertGetId([
                'tenant_id' => $debt->tenant_id, 'code' => $debt->code, 'customer_id' => $debt->customer_id,
                'sales_order_id' => $order->id, 'currency_id' => $currency->id, 'legacy_tenant_debt_id' => $debt->id,
                'original_amount' => $debt->amount, 'balance_amount' => $debt->principal_balance,
                'apply_interest' => false,
                'status' => $debt->is_paid || (float) $debt->principal_balance <= 0.001
                    ? 'SETTLED'
                    : ((float) $debt->principal_balance < (float) $debt->amount ? 'PARTIAL' : 'OPEN'),
                'created_by' => $debt->created_by, 'created_at' => $debt->created_at, 'updated_at' => $debt->updated_at,
            ]);
            DB::table('tenant_debt_payments')->where('tenant_id', $debt->tenant_id)->where('debt_id', $debt->id)->orderBy('id')->get()->each(function (object $payment) use ($debt, $receivableId): void {
                DB::table('sale_receivable_payments')->insert([
                    'tenant_id' => $debt->tenant_id, 'code' => $payment->code, 'sale_receivable_id' => $receivableId,
                    'paid_at' => substr((string) $payment->payment_at, 0, 10), 'amount' => $payment->principal_paid,
                    'financial_account_id' => $payment->accept_account_id,
                    'reference' => null, 'note' => 'Migrated sale receivable collection', 'created_by' => $payment->created_by,
                    'created_at' => $payment->created_at, 'updated_at' => $payment->updated_at,
                ]);
            });
            DB::table('tenant_debts')->where('id', $debt->id)->update(['source_type' => 'SALE_ARCHIVED']);
        });
    }

    // Drop only the new ledger tables when rolling back this migration.
    public function down(): void
    {
        // Restore legacy sale debts as active debt rows when rolling back.
        DB::table('tenant_debts')->where('source_type', 'SALE_ARCHIVED')->update(['source_type' => 'SALE']);
        Schema::dropIfExists('sale_receivable_adjustments');
        Schema::dropIfExists('sale_receivable_payments');
        Schema::dropIfExists('sale_receivables');
    }
};
