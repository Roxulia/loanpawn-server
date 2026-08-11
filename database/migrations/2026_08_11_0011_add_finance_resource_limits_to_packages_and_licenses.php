<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_account_count')->nullable()->after('max_staff_count');
            $table->unsignedInteger('max_currency_type_count')->nullable()->after('max_account_count');
            $table->unsignedInteger('max_exchange_pair_count')->nullable()->after('max_currency_type_count');
        });

        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->unsignedInteger('current_account_count')->default(0)->after('current_staff_count');
            $table->unsignedInteger('current_currency_type_count')->default(0)->after('current_account_count');
            $table->unsignedInteger('current_exchange_pair_count')->default(0)->after('current_currency_type_count');
        });

        foreach ([
            'trial' => [1, 3, 2],
            'basic' => [5, 10, 10],
            'premium' => [null, null, null],
            'budgeting-trial' => [1, 3, 2],
            'budgeting-basic' => [5, 10, 10],
            'budgeting-premium' => [null, null, null],
        ] as $code => [$maxAccounts, $maxCurrencies, $maxExchangePairs]) {
            DB::table('packages')->where('code', $code)->update([
                'max_account_count' => $maxAccounts,
                'max_currency_type_count' => $maxCurrencies,
                'max_exchange_pair_count' => $maxExchangePairs,
            ]);
        }

        DB::table('tenant_licenses')->select(['id', 'tenant_id'])->orderBy('id')->each(function (object $license): void {
            DB::table('tenant_licenses')->where('id', $license->id)->update([
                'current_account_count' => DB::table('financial_accounts')
                    ->where('tenant_id', $license->tenant_id)
                    ->where('is_deleted', false)
                    ->count(),
                'current_currency_type_count' => DB::table('currencies')
                    ->where('tenant_id', $license->tenant_id)
                    ->whereNull('deleted_at')
                    ->count(),
                'current_exchange_pair_count' => DB::table('exchange_rate_pairs')
                    ->where('tenant_id', $license->tenant_id)
                    ->whereNull('deleted_at')
                    ->count(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->dropColumn([
                'current_account_count',
                'current_currency_type_count',
                'current_exchange_pair_count',
            ]);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'max_account_count',
                'max_currency_type_count',
                'max_exchange_pair_count',
            ]);
        });
    }
};
