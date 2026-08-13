<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_exchange_rate_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->foreignId('exchange_rate_pair_id')->constrained('exchange_rate_pairs')->restrictOnDelete();
            $table->date('rate_date');
            $table->decimal('open_rate', 28, 12);
            $table->decimal('high_rate', 28, 12);
            $table->decimal('low_rate', 28, 12);
            $table->decimal('close_rate', 28, 12);
            $table->unsignedInteger('entry_count');
            $table->foreignId('first_entry_id')->nullable()->constrained('exchange_rate_entries')->nullOnDelete();
            $table->foreignId('last_entry_id')->nullable()->constrained('exchange_rate_entries')->nullOnDelete();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['scope_key', 'exchange_rate_pair_id', 'rate_date'], 'daily_rate_summary_scope_pair_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_exchange_rate_summaries');
    }
};
