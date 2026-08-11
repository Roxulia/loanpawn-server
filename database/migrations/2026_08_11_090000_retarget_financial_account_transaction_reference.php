<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->dropForeign(['related_transaction_id']);
        });

        DB::table('financial_account_transactions')
            ->whereNotNull('related_transaction_id')
            ->whereNotIn(
                'related_transaction_id',
                DB::table('tenant_accounting_transactions')->select('id')
            )
            ->update(['related_transaction_id' => null]);

        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->foreign('related_transaction_id')
                ->references('id')
                ->on('tenant_accounting_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->dropForeign(['related_transaction_id']);
        });

        DB::table('financial_account_transactions')
            ->whereNotNull('related_transaction_id')
            ->whereNotIn(
                'related_transaction_id',
                DB::table('tenant_accountings')->select('id')
            )
            ->update(['related_transaction_id' => null]);

        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->foreign('related_transaction_id')
                ->references('id')
                ->on('tenant_accountings')
                ->nullOnDelete();
        });
    }
};
