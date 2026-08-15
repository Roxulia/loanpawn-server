<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_accounting_monthly_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('month_start');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete()->name('tams_currency_id_foreign');
            $table->foreignId('reporting_currency_id')->nullable()->constrained('currencies')->nullOnDelete()->name('tams_reporting_currency_id_foreign');
            $table->decimal('total_incoming', 20, 4)->default(0);
            $table->decimal('total_outgoing', 20, 4)->default(0);
            $table->decimal('total_internal', 20, 4)->default(0);
            $table->decimal('net_movement', 20, 4)->default(0);
            $table->decimal('reporting_total_incoming', 20, 4)->default(0);
            $table->decimal('reporting_total_outgoing', 20, 4)->default(0);
            $table->decimal('reporting_total_internal', 20, 4)->default(0);
            $table->decimal('reporting_net_movement', 20, 4)->default(0);
            $table->unsignedBigInteger('transaction_count')->default(0);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'month_start', 'currency_id', 'reporting_currency_id'],
                'tams_tenant_month_currency_reporting_unique',
            );
            $table->index(['tenant_id', 'month_start'], 'tams_tenant_month_idx');
        });

        Schema::create('financial_account_transaction_monthly_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete()->name('fams_tenant_id_foreign');
            $table->date('month_start');
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->cascadeOnDelete()->name('fams_financial_account_id_foreign');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete()->name('fams_currency_id_foreign');
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->decimal('net_movement', 20, 4)->default(0);
            $table->unsignedBigInteger('transaction_count')->default(0);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'month_start', 'financial_account_id'],
                'fams_tenant_month_account_unique',
            );
            $table->index(['tenant_id', 'month_start', 'currency_id'], 'fams_tenant_month_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_transaction_monthly_summaries');
        Schema::dropIfExists('tenant_accounting_monthly_summaries');
    }
};
