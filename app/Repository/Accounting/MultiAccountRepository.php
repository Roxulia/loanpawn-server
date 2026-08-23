<?php

namespace App\Repository\Accounting;

use App\Models\CoreModule\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTypes;
use App\Models\PlatformModule\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class MultiAccountRepository
{
    public function paginate(int $tenantId, int $perPage, ?string $search = null, ?int $assignedUserId = null): LengthAwarePaginator
    {
        return FinancialAccount::query()
            ->with(['accountType', 'currency'])
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->when($assignedUserId, fn ($query) => $query->whereHas('assignments', fn ($assignmentQuery) => $assignmentQuery
                ->where('tenant_id', $tenantId)
                ->where('assigned_user_id', $assignedUserId)))
            ->when($search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('account_code', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_default')
            ->orderBy('account_name')
            ->paginate($perPage);
    }

    public function findByCode(int $tenantId, string $accountCode): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->with(['accountType', 'currency'])
            ->where('tenant_id', $tenantId)
            ->where('account_code', $accountCode)
            ->where('is_deleted', false)
            ->first();
    }

    public function findActiveById(int $tenantId, int $accountId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->with(['accountType', 'currency'])
            ->where('tenant_id', $tenantId)
            ->whereKey($accountId)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->first();
    }

    public function findById(int $tenantId, int $accountId): ?FinancialAccount
    {
        return FinancialAccount::query()->with(['accountType', 'currency'])->where('tenant_id', $tenantId)->whereKey($accountId)->first();
    }

    public function findByCodeForUpdate(int $tenantId, string $accountCode): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('account_code', $accountCode)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    public function findVisibleAccountType(int $tenantId, string $code): ?FinancialAccountTypes
    {
        return FinancialAccountTypes::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id IS NULL')
            ->first();
    }

    public function findVisibleCurrency(int $tenantId, string $code): ?Currency
    {
        return Currency::query()
            ->where('code', strtoupper($code))
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id IS NULL')
            ->first();
    }

    public function create(array $data): FinancialAccount
    {
        return FinancialAccount::query()->create($data)->refresh();
    }

    public function update(FinancialAccount $account, array $data): FinancialAccount
    {
        $account->update($data);

        return $account->refresh();
    }

    public function clearDefault(int $tenantId, ?int $exceptId = null): void
    {
        FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where('is_default', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }

    public function defaultAccount(int $tenantId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where('is_default', true)
            ->first();
    }

    public function activeDefaultAccount(int $tenantId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->with(['accountType', 'currency'])
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    public function oldestAccount(int $tenantId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->oldest('id')
            ->first();
    }

    public function tenantIdsWithoutDefault(): Collection
    {
        return Tenant::query()
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('financial_accounts')
                    ->whereColumn('financial_accounts.tenant_id', 'tenants.id')
                    ->where('financial_accounts.is_deleted', false)
                    ->where('financial_accounts.is_active', true)
                    ->where('financial_accounts.is_default', true);
            })
            ->orderBy('id')
            ->pluck('id');
    }
}
