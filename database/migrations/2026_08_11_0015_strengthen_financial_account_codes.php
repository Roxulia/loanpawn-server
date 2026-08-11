<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->string('account_code', 60)->change();
            $table->unique(['tenant_id', 'account_code'], 'financial_accounts_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_tenant_code_unique');
            $table->string('account_code', 30)->change();
        });
    }
};
