<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the catalog unit and the shared Inventory item selected for each purchase order item.
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->string('unit_code', 32)->nullable()->after('unit_label');
            $table->foreignId('inventory_item_id')->nullable()->after('unit_code')->constrained('inventory_items')->restrictOnDelete();
        });

        // Keep receipt custody and acquisition references beside each received purchase order item quantity.
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->string('inventory_location_code', 32)->nullable()->after('received_quantity');
            $table->foreignId('inventory_item_id')->nullable()->after('inventory_location_code')->constrained('inventory_items')->restrictOnDelete();
            $table->json('inventory_unit_codes')->nullable()->after('inventory_item_id');
            $table->string('owned_item_code', 32)->nullable()->after('inventory_unit_codes');
            $table->string('acquisition_lot_code', 32)->nullable()->after('owned_item_code');
            $table->decimal('received_value', 15, 2)->default(0)->after('acquisition_lot_code');
        });

        // Keep return credits distinct from physical receipt and cash refund values.
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->decimal('supplier_credit_applied_amount', 15, 2)->default(0)->after('note');
        });
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->json('inventory_unit_codes')->nullable()->after('returned_quantity');
            $table->string('supplier_credit_code', 32)->nullable()->after('inventory_unit_codes');
            $table->decimal('supplier_credit_amount', 15, 2)->default(0)->after('supplier_credit_code');
            $table->decimal('supplier_payable_adjusted_amount', 15, 2)->default(0)->after('supplier_credit_amount');
            $table->decimal('cash_refund_amount', 15, 2)->default(0)->after('supplier_payable_adjusted_amount');
            $table->foreignId('financial_account_id')->nullable()->after('cash_refund_amount')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('accounting_transaction_id')->nullable()->after('financial_account_id');
        });

        // Link purchase payments and supplier refunds to the actual account and accounting entry that posted them.
        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->foreignId('financial_account_id')->nullable()->after('amount')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('accounting_transaction_id')->nullable()->after('financial_account_id');
        });
        Schema::table('purchase_refunds', function (Blueprint $table): void {
            $table->foreignId('financial_account_id')->nullable()->after('amount')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('accounting_transaction_id')->nullable()->after('financial_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn('supplier_credit_applied_amount');
        });
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn(['inventory_unit_codes', 'supplier_credit_code', 'supplier_credit_amount', 'supplier_payable_adjusted_amount', 'cash_refund_amount', 'accounting_transaction_id']);
        });
        Schema::table('purchase_refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn('accounting_transaction_id');
        });
        Schema::table('purchase_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn('accounting_transaction_id');
        });
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('inventory_location_code');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn(['inventory_unit_codes', 'owned_item_code', 'acquisition_lot_code', 'received_value']);
        });
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('unit_code');
        });
    }
};
