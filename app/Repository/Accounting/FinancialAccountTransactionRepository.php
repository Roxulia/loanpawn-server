<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use Illuminate\Database\Eloquent\Collection;

class FinancialAccountTransactionRepository
{
    public function create(array $data): FinancialAccountTransaction
    {
        return FinancialAccountTransaction::query()->create($data)->refresh();
    }

    public function lockAccount(int $tenantId, int $accountId): ?FinancialAccount
    {
        return FinancialAccount::query()->where('tenant_id', $tenantId)->whereKey($accountId)->lockForUpdate()->first();
    }

    public function updateBalance(FinancialAccount $account, float $balance): FinancialAccount
    {
        $account->update(['balance' => $balance, 'update_key' => $account->update_key + 1]);

        return $account->refresh();
    }

    /** @return Collection<int, FinancialAccountTransaction> */
    public function unreversedForReference(int $tenantId, string $referenceNumber, string $referenceType): Collection
    {
        return FinancialAccountTransaction::query()
            ->with('financialAccount')
            ->where('tenant_id', $tenantId)
            ->where('reference_number', $referenceNumber)
            ->where('reference_type', $referenceType)
            ->where('transaction_type', '!=', 'REVERSAL')
            ->whereDoesntHave('reversals')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function ledgerBalance(int $tenantId, int $accountId): float
    {
        return (float) FinancialAccountTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('financial_account_id', $accountId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) AS balance")
            ->value('balance');
    }

    /** @return Collection<int, FinancialAccount> */
    public function accounts(?int $tenantId = null): Collection
    {
        return FinancialAccount::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('tenant_id')->orderBy('id')->get();
    }
}
