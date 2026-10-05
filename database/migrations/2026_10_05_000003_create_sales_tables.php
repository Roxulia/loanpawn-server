<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT'); $table->date('sold_at'); $table->string('currency_code', 12);
            $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ord_tenant_code_uq'); $table->index(['tenant_id', 'status', 'sold_at'], 'sale_ord_status_date_ix');
        });
        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('owned_item_id')->constrained('owned_items')->restrictOnDelete();
            $table->string('item_description', 255); $table->string('tracking_mode', 16); $table->string('unit_label', 80);
            $table->decimal('ordered_quantity', 15, 3); $table->decimal('unit_price', 15, 2); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_line_tenant_code_uq'); $table->index(['tenant_id', 'sales_order_id'], 'sale_line_order_ix');
        });
        Schema::create('sales_order_reservations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('inventory_location_code', 32);
            $table->string('inventory_reservation_code', 32); $table->decimal('quantity', 15, 3);
            $table->decimal('remaining_quantity', 15, 3); $table->json('inventory_unit_codes')->nullable();
            $table->string('status', 16)->default('ACTIVE'); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_res_tenant_code_uq');
            $table->index(['tenant_id', 'sales_order_line_id', 'status'], 'sale_res_line_status_ix');
        });
        Schema::create('sales_payments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->date('paid_at'); $table->decimal('amount', 15, 2); $table->string('reference', 120)->nullable();
            $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_pay_tenant_code_uq'); $table->index(['tenant_id', 'sales_order_id', 'paid_at'], 'sale_pay_order_date_ix');
        });
        Schema::create('sales_payment_refunds', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_payment_id')->constrained('sales_payments')->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_transaction_id')->nullable()->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->date('refunded_at'); $table->decimal('amount', 15, 2); $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ref_tenant_code_uq'); $table->index(['tenant_id', 'sales_payment_id'], 'sale_ref_payment_ix');
        });
        Schema::create('sales_deliveries', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('delivered_at'); $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_del_tenant_code_uq'); $table->index(['tenant_id', 'sales_order_id', 'delivered_at'], 'sale_del_order_date_ix');
        });
        Schema::create('sales_delivery_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_delivery_id')->constrained('sales_deliveries')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('acquisition_lot_id')->constrained('acquisition_lots')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 3); $table->decimal('unit_cost', 15, 4); $table->decimal('unit_price', 15, 2);
            $table->json('inventory_unit_codes')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_alloc_tenant_code_uq'); $table->index(['tenant_id', 'sales_order_line_id'], 'sale_alloc_line_ix');
        });
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('returned_at'); $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ret_tenant_code_uq'); $table->index(['tenant_id', 'sales_order_id'], 'sale_ret_order_ix');
        });
        Schema::create('sales_return_lines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_delivery_allocation_id')->constrained('sales_delivery_allocations')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 3); $table->decimal('cash_refund_amount', 15, 2)->default(0);
            $table->string('inventory_unit_codes', 1000)->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'sale_ret_line_tenant_code_uq'); $table->index(['tenant_id', 'sales_delivery_allocation_id'], 'sale_ret_alloc_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines'); Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_delivery_allocations'); Schema::dropIfExists('sales_deliveries');
        Schema::dropIfExists('sales_payment_refunds'); Schema::dropIfExists('sales_payments');
        Schema::dropIfExists('sales_order_reservations'); Schema::dropIfExists('sales_order_lines'); Schema::dropIfExists('sales_orders');
    }
};
