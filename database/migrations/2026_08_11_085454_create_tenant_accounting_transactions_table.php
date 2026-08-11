<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_accounting_transactions', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant
             */
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Cash-flow direction:
             *
             * incoming
             * outgoing
             * internal
             */
            $table->string('transaction_type', 30);

            /*
             * Accounting classification:
             *
             * revenue
             * expense
             * asset
             * liability
             * equity
             * internal
             *
             * Nullable mainly for legacy migration rows where
             * classification cannot be determined safely.
             */
            $table->string('accounting_category', 30)->nullable();

            /*
             * Optional tenant/system defined revenue/expense category.
             *
             * Example:
             * - Pawn Interest
             * - Salary
             * - Electricity
             * - Rent
             */
            $table->unsignedBigInteger('accounting_subcategory_id')->nullable();

            /*
             * Amount in the transaction's original currency.
             */
            $table->decimal('amount', 18, 4);

            /*
             * Original transaction currency.
             *
             * Nullable to support migrated legacy records where
             * the old accounting table did not store currency.
             */
            $table->unsignedBigInteger('currency_id')->nullable();

            /*
             * Amount converted to tenant's reporting/default currency.
             */
            $table->decimal('reporting_amount', 18, 4)->nullable();

            /*
             * Exchange rate snapshot used at transaction time.
             *
             * Example:
             * 1 USD = 4500 MMK
             */
            $table->decimal('exchange_rate', 20, 10)->nullable();

            $table->string('description', 500)->nullable();

            /*
             * Source business model.
             *
             * Examples:
             * App\Models\PawnModule\PawnInterestPayment
             * App\Models\CoreModule\TenantExpense
             */
            $table->nullableMorphs('reference');

            /*
             * Actual business transaction date/time.
             *
             * Do not rely only on created_at because transactions
             * may be entered later for an earlier business date.
             */
            $table->dateTime('occurred_at');

            /*
             * Tenant user who created the transaction.
             *
             * Nullable for:
             * - system generated rows
             * - migration
             * - scheduled jobs
             */
            $table->unsignedBigInteger('created_by')->nullable();

            /*
             * Used only for migrated tenant_accountings rows.
             *
             * Allows:
             * - migration traceability
             * - idempotent reruns
             * - reconciliation
             */
            $table->unsignedBigInteger('legacy_accounting_id')->nullable();

            /*
             * Optimistic locking/versioning.
             */
            $table->unsignedInteger('update_key')->default(0);

            /*
             * Keep for compatibility with existing accounting history.
             */
            $table->boolean('is_deleted')->default(false);

            $table->timestamps();

            /*
             * Indexes
             */
            $table->index(
                ['tenant_id', 'occurred_at'],
                'tat_tenant_occurred_idx'
            );

            $table->index(
                ['tenant_id', 'transaction_type'],
                'tat_tenant_type_idx'
            );

            $table->index(
                ['tenant_id', 'accounting_category'],
                'tat_tenant_category_idx'
            );

            $table->index(
                ['tenant_id', 'currency_id'],
                'tat_tenant_currency_idx'
            );

            $table->index(
                ['tenant_id', 'is_deleted', 'occurred_at'],
                'tat_history_idx'
            );

            /*
             * Prevent the same old accounting row from being
             * migrated twice for a tenant.
             */
            $table->unique(
                ['tenant_id', 'legacy_accounting_id'],
                'tat_legacy_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_accounting_transactions');
    }
};
