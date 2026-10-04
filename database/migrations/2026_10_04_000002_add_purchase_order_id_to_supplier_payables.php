<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payables', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->after('purchase_receipt_id')->constrained('purchase_orders')->restrictOnDelete();
        });

        DB::statement('UPDATE supplier_payables JOIN purchase_receipts ON purchase_receipts.id = supplier_payables.purchase_receipt_id SET supplier_payables.purchase_order_id = purchase_receipts.purchase_order_id WHERE supplier_payables.purchase_order_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('supplier_payables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};