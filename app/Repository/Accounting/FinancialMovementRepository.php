<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccountTransaction;
use App\Models\FinancialAccountTransactionMonthlySummary;
use App\Models\TenantAccountingMonthlySummary;
use App\Models\TenantAccountingTransactions;
use Illuminate\Support\Collection;

class FinancialMovementRepository
{
    public function accountingSummary(int $tenantId, string $monthStart): Collection
    {
        return TenantAccountingMonthlySummary::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('month_start', $monthStart)
            ->get();
    }

    public function accountingBase(int $tenantId, string $startDate, string $endDate, int $reportingCurrencyId): Collection
    {
        return TenantAccountingTransactions::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where(function ($query) use ($startDate, $endDate): void {
                $query->whereBetween('business_date', [$startDate, $endDate])
                    ->orWhere(function ($query) use ($startDate, $endDate): void {
                        $query->whereNull('business_date')
                            ->whereBetween('occurred_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);
                    });
            })
            ->selectRaw('currency_id')
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'incoming' THEN amount ELSE 0 END) AS total_incoming")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'outgoing' THEN amount ELSE 0 END) AS total_outgoing")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'internal' THEN amount ELSE 0 END) AS total_internal")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'incoming' THEN COALESCE(reporting_amount, amount) ELSE 0 END) AS reporting_total_incoming")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'outgoing' THEN COALESCE(reporting_amount, amount) ELSE 0 END) AS reporting_total_outgoing")
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('currency_id')
            ->get()
            ->each(fn ($row) => $row->setAttribute('reporting_currency_id', $reportingCurrencyId));
    }

    public function accountSummary(int $tenantId, string $monthStart): Collection
    {
        return FinancialAccountTransactionMonthlySummary::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('month_start', $monthStart)
            ->get();
    }

    public function accountBase(int $tenantId, string $startDate, string $endDate): Collection
    {
        return FinancialAccountTransaction::query()
            ->join('financial_accounts', function ($join): void {
                $join->on('financial_accounts.id', '=', 'financial_account_transactions.financial_account_id')
                    ->on('financial_accounts.tenant_id', '=', 'financial_account_transactions.tenant_id');
            })
            ->where('financial_account_transactions.tenant_id', $tenantId)
            ->whereBetween('financial_account_transactions.created_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"])
            ->selectRaw('financial_account_transactions.financial_account_id, financial_accounts.currency_id')
            ->selectRaw("SUM(CASE WHEN financial_account_transactions.direction = 'debit' THEN financial_account_transactions.amount ELSE 0 END) AS total_debit")
            ->selectRaw("SUM(CASE WHEN financial_account_transactions.direction = 'credit' THEN financial_account_transactions.amount ELSE 0 END) AS total_credit")
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('financial_account_transactions.financial_account_id', 'financial_accounts.currency_id')
            ->get();
    }
}
