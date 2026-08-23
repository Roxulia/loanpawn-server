<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('financial_account_assignments')
            ->whereNull('tenant_id')
            ->orWhereNull('assigned_user_id')
            ->delete();

        $duplicates = DB::table('financial_account_assignments')
            ->select('tenant_id', 'financial_account_id', 'assigned_user_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('tenant_id', 'financial_account_id', 'assigned_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $duplicate) {
            DB::table('financial_account_assignments')
                ->where('tenant_id', $duplicate->tenant_id)
                ->where('financial_account_id', $duplicate->financial_account_id)
                ->where('assigned_user_id', $duplicate->assigned_user_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('financial_account_assignments', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['assigned_user_id']);
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $table->unsignedBigInteger('assigned_user_id')->nullable(false)->change();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete()->name('faa_tenant_id_foreign');
            $table->foreign('assigned_user_id')->references('id')->on('tenant_users')->cascadeOnDelete()->name('faa_assigned_user_id_foreign');
            $table->unique(
                ['tenant_id', 'financial_account_id', 'assigned_user_id'],
                'financial_account_assignments_tenant_account_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('financial_account_assignments', function (Blueprint $table): void {
            $table->dropUnique('financial_account_assignments_tenant_account_user_unique');
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['assigned_user_id']);
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
            $table->unsignedBigInteger('assigned_user_id')->nullable()->change();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('tenant_users')->nullOnDelete();
        });
    }
};
