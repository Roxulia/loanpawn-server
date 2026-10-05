<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep existing locations available for sale until an operator marks a quarantine location.
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->boolean('is_sellable')->default(true)->after('is_active');
        });

        // Create Delivery-owned dispatches and the many-to-many order association.
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->string('status', 20)->default('PLANNED'); $table->date('scheduled_at')->nullable();
            $table->date('delivered_at')->nullable(); $table->string('recipient_name', 160)->nullable();
            $table->string('address', 500)->nullable(); $table->string('reference', 120)->nullable();
            $table->text('note')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'delivery_tenant_code_uq');
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'delivery_status_date_ix');
        });
        Schema::create('delivery_sales_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id');
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete(); $table->timestamps();
            $table->unique(['tenant_id', 'delivery_id', 'sales_order_id'], 'delivery_order_pair_uq');
            $table->index(['tenant_id', 'sales_order_id'], 'delivery_order_sale_ix');
        });
        Schema::create('delivery_order_charges', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id');
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->decimal('amount', 15, 2)->default(0); $table->string('currency_code', 12);
            $table->foreignId('recognized_delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->timestamps(); $table->unique(['tenant_id', 'sales_order_id'], 'delivery_charge_sale_uq');
        });
        Schema::create('delivery_items', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code', 32);
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->decimal('planned_quantity', 15, 3); $table->decimal('delivered_quantity', 15, 3)->nullable();
            $table->json('inventory_unit_codes')->nullable(); $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'delivery_item_code_uq');
            $table->unique(['tenant_id', 'delivery_id', 'sales_order_line_id'], 'delivery_item_sale_line_uq');
            $table->index(['tenant_id', 'sales_order_line_id'], 'delivery_item_line_ix');
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('receivable_credit_amount', 15, 2)->default(0);
            $table->decimal('cash_refund_amount', 15, 2)->default(0);
        });
        // New fulfillment records use deliveries; retain nullable legacy references without backfilling them.
        Schema::table('sales_delivery_allocations', function (Blueprint $table): void {
            $table->foreignId('sales_delivery_id')->nullable()->change();
        });
        Schema::table('sale_receivables', function (Blueprint $table): void {
            $table->foreignId('sales_delivery_id')->nullable()->change();
        });
        Schema::table('sales_delivery_allocations', function (Blueprint $table): void {
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->foreignId('cogs_expense_transaction_id')->nullable()
                ->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->foreignId('inventory_asset_transaction_id')->nullable()
                ->constrained('tenant_accounting_transactions')->nullOnDelete();
        });

        // Persist return disposition and the inventory/cost reversal that fulfils each return line.
        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->string('disposition', 20)->default('RESTOCK')->after('cash_refund_amount');
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('cogs_reversal_transaction_id')->nullable()
                ->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->foreignId('inventory_asset_return_transaction_id')->nullable()
                ->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->foreignId('inventory_writeoff_transaction_id')->nullable()
                ->constrained('tenant_accounting_transactions')->nullOnDelete();
            $table->decimal('receivable_credit_amount', 15, 2)->default(0);
        });
        Schema::table('sale_receivables', function (Blueprint $table): void {
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->unique(['tenant_id', 'sales_order_id', 'delivery_id'], 'sale_ar_order_delivery_uq');
        });

    }

    public function down(): void
    {
        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_writeoff_transaction_id');
            $table->dropConstrainedForeignId('inventory_asset_return_transaction_id');
            $table->dropConstrainedForeignId('cogs_reversal_transaction_id');
            $table->dropConstrainedForeignId('inventory_movement_id');
            $table->dropColumn(['disposition', 'receivable_credit_amount']);
        });
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropColumn(['total_amount', 'receivable_credit_amount', 'cash_refund_amount']));
        Schema::table('sale_receivables', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_id'));
        Schema::table('sales_delivery_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_id');
            $table->dropConstrainedForeignId('inventory_asset_transaction_id');
            $table->dropConstrainedForeignId('cogs_expense_transaction_id');
        });
        Schema::dropIfExists('delivery_order_charges');
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('delivery_sales_orders');
        Schema::dropIfExists('deliveries');
        Schema::table('inventory_locations', fn (Blueprint $table) => $table->dropColumn('is_sellable'));
    }
};
