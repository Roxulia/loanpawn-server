<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->decimal('balance', 20, 4)->default(0)->change();
        });

        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->decimal('amount', 20, 4)->change();
            $table->foreignId('reversed_transaction_id')->nullable()->unique()->after('related_transaction_id')->constrained('financial_account_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_transaction_id');
            $table->decimal('amount', 15, 2)->change();
        });

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->decimal('balance', 15, 2)->default(0)->change();
        });
    }
};
