<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A schedule is the editable template. Individual due payments are
        // snapshotted separately by the occurrence table created afterward.
        Schema::create('tenant_scheduled_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'tse_tenant_fk')
                ->cascadeOnDelete();
            $table->string('code');
            $table->foreignId('account_id')
                ->constrained('financial_accounts', indexName: 'tse_account_fk')
                ->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->foreignId('expense_type_id')->nullable()
                ->constrained('expense_types', indexName: 'tse_type_fk')
                ->nullOnDelete();
            $table->string('recurrence_type', 20);
            $table->date('start_date');
            $table->time('scheduled_time');
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('weekly_day')->nullable();
            $table->unsignedTinyInteger('monthly_anchor_day')->nullable();
            $table->date('next_due_date')->nullable();
            $table->date('last_due_date')->nullable();
            $table->dateTime('last_paid_at')->nullable();
            $table->string('last_result', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('paused_at')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('tenant_users', indexName: 'tse_creator_fk')
                ->nullOnDelete();
            $table->unsignedInteger('update_key')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'tse_code_uq');
            $table->index(['tenant_id', 'status', 'next_due_date'], 'tse_due_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_scheduled_expenses');
    }
};
