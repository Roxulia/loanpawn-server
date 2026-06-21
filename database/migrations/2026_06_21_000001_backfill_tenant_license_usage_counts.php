<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth()->toDateString();
        $monthEnd = $now->endOfMonth()->toDateString();

        $staffCounts = DB::table('tenant_users')
            ->where('is_deleted', false)
            ->select('tenant_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $slipCounts = DB::table('pawn_loan_contract_slips')
            ->where('is_deleted', false)
            ->whereBetween('created_date', [$monthStart, $monthEnd])
            ->select('tenant_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        DB::table('tenant_licenses')
            ->where('is_deleted', false)
            ->orderBy('id')
            ->select(['id', 'tenant_id'])
            ->chunkById(500, function ($licenses) use ($staffCounts, $slipCounts): void {
                foreach ($licenses as $license) {
                    DB::table('tenant_licenses')
                        ->where('id', $license->id)
                        ->update([
                            'current_staff_count' => (int) ($staffCounts[$license->tenant_id] ?? 0),
                            'current_month_slip_count' => (int) ($slipCounts[$license->tenant_id] ?? 0),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // This migration backfills derived counters from source tables.
        // Previous counter values cannot be restored safely.
    }
};
