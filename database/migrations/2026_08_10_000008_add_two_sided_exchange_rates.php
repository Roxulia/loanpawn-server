<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rate_entries', function (Blueprint $table): void {
            $table->decimal('buying_rate', 28, 12)->after('exchange_rate_pair_id');
            $table->decimal('selling_rate', 28, 12)->after('buying_rate');
            $table->decimal('rate', 28, 12)->nullable()->change();
        });

        Schema::table('daily_exchange_rate_summaries', function (Blueprint $table): void {
            $table->decimal('buying_open', 28, 12)->after('rate_date');
            $table->decimal('buying_high', 28, 12)->after('buying_open');
            $table->decimal('buying_low', 28, 12)->after('buying_high');
            $table->decimal('buying_close', 28, 12)->after('buying_low');
            $table->decimal('selling_open', 28, 12)->after('buying_close');
            $table->decimal('selling_high', 28, 12)->after('selling_open');
            $table->decimal('selling_low', 28, 12)->after('selling_high');
            $table->decimal('selling_close', 28, 12)->after('selling_low');
            $table->decimal('open_rate', 28, 12)->nullable()->change();
            $table->decimal('high_rate', 28, 12)->nullable()->change();
            $table->decimal('low_rate', 28, 12)->nullable()->change();
            $table->decimal('close_rate', 28, 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rate_entries', function (Blueprint $table): void {
            $table->dropColumn(['buying_rate', 'selling_rate']);
        });

        Schema::table('daily_exchange_rate_summaries', function (Blueprint $table): void {
            $table->dropColumn(['buying_open', 'buying_high', 'buying_low', 'buying_close', 'selling_open', 'selling_high', 'selling_low', 'selling_close']);
        });
    }
};
