<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccountTransaction;
use App\Models\FinancialAccountTransactionMonthlySummary;
use App\Models\PlatformModule\Tenant;
use Illuminate\Support\Collection;

class FinancialAccountMonthlySummaryRepository
{
    public function tenantIds(): Collection
    {
        return Tenant::query()->orderBy('id')->pluck('id');
    }

    public function movementRows(int $tenantId, string $monthStart, string $monthEnd): Collection
    {
        return FinancialAccountTransaction::query()
            ->join('financial_accounts', function ($join): void {
                $join->on('financial_accounts.id', '=', 'financial_account_transactions.financial_account_id')
                    ->on('financial_accounts.tenant_id', '=', 'financial_account_transactions.tenant_id');
            })
            ->where('financial_account_transactions.tenant_id', $tenantId)
            ->whereBetween('financial_account_transactions.created_at', ["{$monthStart} 00:00:00", "{$monthEnd} 23:59:59"])
            ->selectRaw('financial_account_transactions.financial_account_id, financial_accounts.currency_id')
            ->selectRaw("SUM(CASE WHEN financial_account_transactions.direction = 'debit' THEN financial_account_transactions.amount ELSE 0 END) AS total_debit")
            ->selectRaw("SUM(CASE WHEN financial_account_transactions.direction = 'credit' THEN financial_account_transactions.amount ELSE 0 END) AS total_credit")
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('financial_account_transactions.financial_account_id', 'financial_accounts.currency_id')
            ->get();
    }

    public function replaceMonth(int $tenantId, string $monthStart, Collection $rows): void
    {
        FinancialAccountTransactionMonthlySummary::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('month_start', $monthStart)
            ->delete();

        foreach ($rows as $row) {
            $debit = (float) $row->total_debit;
            $credit = (float) $row->total_credit;

            FinancialAccountTransactionMonthlySummary::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'month_start' => $monthStart,
                'financial_account_id' => (int) $row->financial_account_id,
                'currency_id' => (int) $row->currency_id,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'net_movement' => $debit - $credit,
                'transaction_count' => (int) $row->transaction_count,
                'calculated_at' => now(),
            ]);
        }
    }
}
