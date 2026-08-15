<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_currency_recalculations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete()->name('rcr_tenant_id_foreign');
            $table->foreignId('previous_reporting_currency_id')->constrained('currencies')->restrictOnDelete()->name('rcr_previous_reporting_currency_id_foreign');
            $table->foreignId('requested_reporting_currency_id')->constrained('currencies')->restrictOnDelete()->name('rcr_requested_reporting_currency_id_foreign');
            $table->date('window_start');
            $table->date('window_end');
            $table->string('status', 30)->default('queued');
            $table->json('missing_rates')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'rcr_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_currency_recalculations');
    }
};
