<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SINGLE_ACCOUNT_TABLES = [
        'pawn_loan_contract_slips',
        'pawn_redemptions',
        'tenant_expenses',
        'tenant_capitals',
    ];

    private const SPLIT_ACCOUNT_TABLES = [
        'pawn_interest_payments',
        'tenant_debts',
    ];

    public function up(): void
    {
        foreach (self::SINGLE_ACCOUNT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('account_id')
                    ->nullable()
                    ->constrained('financial_accounts')
                    ->nullOnDelete();
            });
        }

        foreach (self::SPLIT_ACCOUNT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('created_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
                $table->foreignId('accept_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::SPLIT_ACCOUNT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('accept_account_id');
                $table->dropConstrainedForeignId('created_account_id');
            });
        }

        foreach (self::SINGLE_ACCOUNT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('account_id');
            });
        }
    }
};
