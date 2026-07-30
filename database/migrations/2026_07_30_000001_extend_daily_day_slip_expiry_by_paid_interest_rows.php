<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pawn_loan_contract_slips')
            ->join('interest_types', 'interest_types.id', '=', 'pawn_loan_contract_slips.interest_type_id')
            ->whereNotNull('pawn_loan_contract_slips.expire_at')
            ->whereRaw('LOWER(TRIM(pawn_loan_contract_slips.expiry_quota_type)) = ?', ['day'])
            ->where(function ($query): void {
                $query
                    ->whereRaw('LOWER(TRIM(interest_types.code)) IN (?, ?)', ['daily', 'day'])
                    ->orWhereRaw('LOWER(TRIM(interest_types.name)) IN (?, ?)', ['daily', 'day']);
            })
            ->select([
                'pawn_loan_contract_slips.id',
                'pawn_loan_contract_slips.expire_at',
                'pawn_loan_contract_slips.update_key',
            ])
            ->orderBy('pawn_loan_contract_slips.id')
            ->chunkById(500, function ($slips): void {
                $paidRowCounts = DB::table('pawn_interest_payments')
                    ->whereIn('slip_id', $slips->pluck('id'))
                    ->where('is_paid', true)
                    ->selectRaw('slip_id, COUNT(*) as paid_row_count')
                    ->groupBy('slip_id')
                    ->pluck('paid_row_count', 'slip_id');

                foreach ($slips as $slip) {
                    $paidRowCount = (int) ($paidRowCounts[$slip->id] ?? 0);

                    if ($paidRowCount === 0) {
                        continue;
                    }

                    DB::table('pawn_loan_contract_slips')
                        ->where('id', $slip->id)
                        ->update([
                            'expire_at' => CarbonImmutable::parse($slip->expire_at)
                                ->startOfDay()
                                ->addDays($paidRowCount),
                            'update_key' => (int) $slip->update_key + 1,
                            'updated_at' => now(),
                        ]);
                }
            }, 'pawn_loan_contract_slips.id', 'id');
    }

    public function down(): void
    {
        // The previous expiry cannot be reconstructed safely after more payments are recorded.
    }
};
