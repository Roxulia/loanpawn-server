<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_accounting_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('timezone', 64);
            $table->string('status', 20);
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->string('opening_source', 30)->nullable();
            $table->dateTime('closing_started_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('effective_closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->string('closing_source', 30)->nullable();
            $table->json('close_metadata')->nullable();
            $table->unsignedInteger('update_key')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'business_date'], 'tad_tenant_date_unique');
            $table->index(['tenant_id', 'status', 'business_date'], 'tad_status_idx');
        });

        Schema::create('tenant_accounting_day_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('accounting_day_id')->constrained('tenant_accounting_days')->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('total_incoming', 18, 4)->default(0);
            $table->decimal('total_outgoing', 18, 4)->default(0);
            $table->decimal('closing_balance', 18, 4)->default(0);
            $table->decimal('revenue', 18, 4)->default(0);
            $table->decimal('expense', 18, 4)->default(0);
            $table->decimal('profit', 18, 4)->default(0);
            $table->json('category_totals')->nullable();
            $table->timestamps();

            $table->index(['accounting_day_id', 'currency_id'], 'tads_day_currency_idx');
        });

        Schema::create('tenant_accounting_day_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_enabled')->default(false);
            $table->time('open_time');
            $table->time('close_time');
            $table->unsignedInteger('update_key')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'weekday'], 'tads_tenant_weekday_unique');
        });

        Schema::table('tenant_accounting_transactions', function (Blueprint $table): void {
            $table->foreignId('accounting_day_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('tenant_accounting_days')
                ->restrictOnDelete();
            $table->date('business_date')->nullable()->after('accounting_day_id');
            $table->index(['tenant_id', 'business_date'], 'tat_business_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_accounting_transactions', function (Blueprint $table): void {
            $table->dropIndex('tat_business_date_idx');
            $table->dropConstrainedForeignId('accounting_day_id');
            $table->dropColumn('business_date');
        });

        Schema::dropIfExists('tenant_accounting_day_schedules');
        Schema::dropIfExists('tenant_accounting_day_summaries');
        Schema::dropIfExists('tenant_accounting_days');
    }
};
