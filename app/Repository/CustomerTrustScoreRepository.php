<?php

namespace App\Repository;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CustomerTrustScoreRepository
{
    public function metricsForCustomer(int $tenantId, int $customerId, CarbonInterface $today): array
    {
        $slipMetrics = DB::table('pawn_loan_contract_slips')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->selectRaw('SUM(CASE WHEN is_deleted = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as slip_count')
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'active' AND is_deleted = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_slip_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'redeemed' AND is_deleted = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as redeemed_slip_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'expired' AND is_deleted = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as expired_slip_count")
            ->selectRaw('SUM(CASE WHEN is_deleted = 0 AND deleted_at IS NULL THEN loan_amount ELSE 0 END) as lifetime_principal')
            ->selectRaw("SUM(CASE WHEN LOWER(status) = 'active' AND is_deleted = 0 AND deleted_at IS NULL THEN loan_amount ELSE 0 END) as active_principal")
            ->selectRaw('MIN(CASE WHEN is_deleted = 0 AND deleted_at IS NULL THEN created_date ELSE NULL END) as first_slip_date')
            ->selectRaw('MAX(CASE WHEN is_deleted = 0 AND deleted_at IS NULL THEN created_date ELSE NULL END) as latest_slip_date')
            ->first();

        $interestMetrics = DB::table('pawn_interest_payments')
            ->join('pawn_loan_contract_slips', 'pawn_loan_contract_slips.id', '=', 'pawn_interest_payments.slip_id')
            ->where('pawn_interest_payments.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.customer_id', $customerId)
            ->where('pawn_loan_contract_slips.is_deleted', false)
            ->whereNull('pawn_loan_contract_slips.deleted_at')
            ->where('pawn_interest_payments.is_deleted', false)
            ->whereDate('pawn_interest_payments.start_period', '<=', $today->toDateString())
            ->selectRaw('COUNT(*) as due_interest_count')
            ->selectRaw('SUM(CASE WHEN pawn_interest_payments.is_paid = 1 THEN 1 ELSE 0 END) as paid_interest_count')
            ->selectRaw('SUM(CASE WHEN pawn_interest_payments.is_paid = 0 THEN 1 ELSE 0 END) as unpaid_due_interest_count')
            ->selectRaw('SUM(CASE WHEN pawn_interest_payments.is_paid = 1 AND pawn_interest_payments.payment_date <= pawn_interest_payments.end_period THEN 1 ELSE 0 END) as on_time_interest_count')
            ->first();

        $debtMetrics = DB::table('tenant_debts')
            ->join('pawn_loan_contract_slips', 'pawn_loan_contract_slips.id', '=', 'tenant_debts.slip_id')
            ->where('tenant_debts.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.customer_id', $customerId)
            ->where('pawn_loan_contract_slips.is_deleted', false)
            ->whereNull('pawn_loan_contract_slips.deleted_at')
            ->where('tenant_debts.is_deleted', false)
            ->selectRaw('COUNT(*) as debt_count')
            ->selectRaw('SUM(CASE WHEN tenant_debts.is_paid = 0 THEN 1 ELSE 0 END) as unpaid_debt_count')
            ->selectRaw('SUM(CASE WHEN tenant_debts.is_paid = 0 THEN tenant_debts.amount ELSE 0 END) as unpaid_debt_amount')
            ->first();

        return [
            'slip_count' => (int) ($slipMetrics->slip_count ?? 0),
            'active_slip_count' => (int) ($slipMetrics->active_slip_count ?? 0),
            'redeemed_slip_count' => (int) ($slipMetrics->redeemed_slip_count ?? 0),
            'expired_slip_count' => (int) ($slipMetrics->expired_slip_count ?? 0),
            'lifetime_principal' => (float) ($slipMetrics->lifetime_principal ?? 0),
            'active_principal' => (float) ($slipMetrics->active_principal ?? 0),
            'first_slip_date' => $slipMetrics->first_slip_date ?? null,
            'latest_slip_date' => $slipMetrics->latest_slip_date ?? null,
            'due_interest_count' => (int) ($interestMetrics->due_interest_count ?? 0),
            'paid_interest_count' => (int) ($interestMetrics->paid_interest_count ?? 0),
            'unpaid_due_interest_count' => (int) ($interestMetrics->unpaid_due_interest_count ?? 0),
            'on_time_interest_count' => (int) ($interestMetrics->on_time_interest_count ?? 0),
            'debt_count' => (int) ($debtMetrics->debt_count ?? 0),
            'unpaid_debt_count' => (int) ($debtMetrics->unpaid_debt_count ?? 0),
            'unpaid_debt_amount' => (float) ($debtMetrics->unpaid_debt_amount ?? 0),
        ];
    }

    public function updateTrustScore(int $tenantId, int $customerId, int $trustScore): void
    {
        DB::table('tenant_customers')
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->where('is_deleted', false)
            ->update([
                'trust_score' => $trustScore,
                'update_key' => DB::raw('update_key + 1'),
                'updated_at' => now(),
            ]);
    }
}
