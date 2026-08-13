<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\DataObjects\RequestObjects\FinancialAccountTransactionFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class FinancialAccountTransactionRepository
{
    public function paginateForAccount(int $tenantId, int $accountId, FinancialAccountTransactionFilter $filter): LengthAwarePaginator
    {
        return FinancialAccountTransaction::query()
            ->with('creator:id,name')
            ->where('tenant_id', $tenantId)
            ->where('financial_account_id', $accountId)
            ->when($filter->search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('transaction_type', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('reference_type', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                });
            })
            ->when($filter->direction, fn ($query, string $direction) => $query->where('direction', $direction))
            ->when($filter->transactionType, fn ($query, string $type) => $query->where('transaction_type', $type))
            ->when($filter->startAt, fn ($query, $startAt) => $query->where('created_at', '>=', $startAt))
            ->when($filter->endAt, fn ($query, $endAt) => $query->where('created_at', '<=', $endAt))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filter->perPage);
    }

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
