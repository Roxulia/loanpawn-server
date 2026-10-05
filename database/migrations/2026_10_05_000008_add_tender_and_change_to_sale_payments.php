<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Store the amount tendered separately from the amount applied to a sale.
    public function up(): void
    {
        Schema::table('sales_payments', function (Blueprint $table): void {
            $table->decimal('payment_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('change_amount', 15, 2)->default(0)->after('payment_amount');
        });
        Schema::table('sale_receivable_payments', function (Blueprint $table): void {
            $table->decimal('payment_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('change_amount', 15, 2)->default(0)->after('payment_amount');
        });

        DB::table('sales_payments')->update(['payment_amount' => DB::raw('amount')]);
        DB::table('sale_receivable_payments')->update(['payment_amount' => DB::raw('amount')]);
    }

    // Remove the tender and change fields while preserving existing applied amounts.
    public function down(): void
    {
        Schema::table('sale_receivable_payments', function (Blueprint $table): void {
            $table->dropColumn(['payment_amount', 'change_amount']);
        });
        Schema::table('sales_payments', function (Blueprint $table): void {
            $table->dropColumn(['payment_amount', 'change_amount']);
        });
    }
};
