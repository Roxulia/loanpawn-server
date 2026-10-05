<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_debts', function (Blueprint $table): void {
            $table->string('source_type', 32)->nullable()->index();
            $table->string('source_code', 32)->nullable()->index();
            $table->string('currency_code', 12)->nullable();
            $table->unique(['tenant_id', 'source_type', 'source_code'], 'tenant_debts_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_debts', function (Blueprint $table): void {
            $table->dropUnique('tenant_debts_source_unique');
            $table->dropColumn(['source_type', 'source_code', 'currency_code']);
        });
    }
};
