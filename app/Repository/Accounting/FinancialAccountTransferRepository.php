<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountsTranfers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class FinancialAccountTransferRepository
{
    /** @return Collection<int, FinancialAccount> */
    public function lockAccounts(int $tenantId, array $accountIds): Collection
    {
        return FinancialAccount::query()
            ->with('currency')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function create(array $data): FinancialAccountsTranfers
    {
        return FinancialAccountsTranfers::query()->create($data)->load(['fromAccount.currency', 'toAccount.currency']);
    }

    public function paginate(int $tenantId, int $perPage): LengthAwarePaginator
    {
        return FinancialAccountsTranfers::query()
            ->with(['fromAccount.currency', 'toAccount.currency'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('transferred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function refresh(FinancialAccountsTranfers $transfer): FinancialAccountsTranfers
    {
        return $transfer->refresh()->load(['fromAccount.currency', 'toAccount.currency']);
    }
}
