<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->foreignId('default_currency_id')
                ->nullable()
                ->after('category')
                ->constrained('currencies')
                ->nullOnDelete();
            $table->foreignId('reporting_currency_id')
                ->nullable()
                ->after('default_currency_id')
                ->constrained('currencies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reporting_currency_id');
            $table->dropConstrainedForeignId('default_currency_id');
        });
    }
};
