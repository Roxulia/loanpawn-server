<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants');

            // Source account
            $table->foreignId('from_account_id')
                ->constrained('financial_accounts');

            // Destination account
            $table->foreignId('to_account_id')
                ->constrained('financial_accounts');

            // Currency snapshot of each side
            $table->foreignId('from_currency_id')
                ->constrained('currencies');

            $table->foreignId('to_currency_id')
                ->constrained('currencies');

            // Always positive values
            $table->decimal('from_amount', 20, 4);
            $table->decimal('to_amount', 20, 4);

            /*
             * Nullable because same-currency transfers
             * do not require an exchange rate.
             *
             * Example:
             * 100 USD -> 450,000 MMK
             * exchange_rate = 4500
             */
            $table->decimal('exchange_rate', 20, 8)->nullable();

            /*
             * Examples:
             * tenant
             * platform
             * manual
             */
            $table->string('exchange_rate_source', 30)->nullable();

            /*
             * Optional transfer fee.
             *
             * Example:
             * KBZ charges MMK 1,000 for the transfer.
             */
            $table->decimal('fee_amount', 20, 4)
                ->default(0);

            /*
             * Account from which the fee is deducted.
             * Usually from_account_id, but keeping it explicit
             * allows other cases.
             */
            $table->foreignId('fee_account_id')
                ->nullable()
                ->constrained('financial_accounts');

            $table->text('note')->nullable();

            /*
             * Actual business date/time of transfer,
             * which may differ from created_at.
             */
            $table->dateTime('transferred_at');

            /*
             * Avoid adding FK constraint here if your tenant-user
             * table name differs.
             */
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            // Common queries
            $table->index([
                'tenant_id',
                'transferred_at',
            ]);

            $table->index([
                'tenant_id',
                'from_account_id',
            ]);

            $table->index([
                'tenant_id',
                'to_account_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_transfers');
    }
};
