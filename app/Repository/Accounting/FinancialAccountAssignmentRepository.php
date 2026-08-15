<?php

namespace App\Repository\Accounting;

use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class FinancialAccountAssignmentRepository
{
    public function findUserByCode(int $tenantId, string $userCode): ?TenantUser
    {
        return TenantUser::query()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('code', $userCode)
            ->where('is_deleted', false)
            ->first();
    }

    public function accountsForUser(int $tenantId, int $userId): Collection
    {
        return FinancialAccount::query()
            ->with(['accountType', 'currency'])
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->whereHas('assignments', fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('assigned_user_id', $userId))
            ->orderByDesc('is_default')
            ->orderBy('account_name')
            ->get();
    }

    public function usersForAccount(int $tenantId, int $accountId): Collection
    {
        return TenantUser::query()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->whereHas('financialAccountAssignments', fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('financial_account_id', $accountId))
            ->orderBy('name')
            ->get();
    }

    public function validAccountIds(int $tenantId, array $accountIds): array
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->whereKey($accountIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function ownerUsers(int $tenantId): Collection
    {
        return TenantUser::query()
            ->where('tenant_users.tenant_id', $tenantId)
            ->where('tenant_users.is_deleted', false)
            ->whereHas('role', fn ($query) => $query->whereRaw('LOWER(name) = ?', ['owner']))
            ->get();
    }

    public function isAssigned(int $tenantId, int $accountId, int $userId): bool
    {
        return FinancialAccountAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('financial_account_id', $accountId)
            ->where('assigned_user_id', $userId)
            ->exists();
    }

    public function syncForUser(int $tenantId, int $userId, array $accountIds): void
    {
        FinancialAccountAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('assigned_user_id', $userId)
            ->whereNotIn('financial_account_id', $accountIds ?: [-1])
            ->delete();

        foreach ($accountIds as $accountId) {
            FinancialAccountAssignment::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'financial_account_id' => $accountId,
                'assigned_user_id' => $userId,
            ]);
        }
    }

    public function assignUsersToAccount(int $tenantId, int $accountId, array $userIds): int
    {
        $created = 0;
        foreach ($userIds as $userId) {
            $assignment = FinancialAccountAssignment::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'financial_account_id' => $accountId,
                'assigned_user_id' => $userId,
            ]);
            $created += $assignment->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    public function removeForAccount(int $tenantId, int $accountId): void
    {
        FinancialAccountAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('financial_account_id', $accountId)
            ->delete();
    }

    public function tenantIds(?int $tenantId = null): SupportCollection
    {
        return FinancialAccount::query()
            ->where('is_deleted', false)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');
    }

    public function accountIds(int $tenantId): array
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
