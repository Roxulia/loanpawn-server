<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Occurrences retain the financial snapshot that was due at a specific
        // local date/time, so later schedule edits cannot rewrite history.
        Schema::create('tenant_scheduled_expense_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'tseo_tenant_fk')
                ->cascadeOnDelete();
            $table->foreignId('scheduled_expense_id')
                ->constrained('tenant_scheduled_expenses', indexName: 'tseo_schedule_fk')
                ->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()
                ->constrained('tenant_expenses', indexName: 'tseo_expense_fk')
                ->nullOnDelete();
            $table->foreignId('account_id')
                ->constrained('financial_accounts', indexName: 'tseo_account_fk')
                ->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->foreignId('expense_type_id')->nullable()
                ->constrained('expense_types', indexName: 'tseo_type_fk')
                ->nullOnDelete();
            $table->date('due_date');
            $table->time('due_time');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            // This identity is also the database-level duplicate execution guard.
            $table->unique(
                ['scheduled_expense_id', 'due_date', 'due_time'],
                'tseo_due_uq',
            );
            $table->index(['tenant_id', 'status', 'due_date'], 'tseo_due_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_scheduled_expense_occurrences');
    }
};
