<?php

namespace App\Repository;

use App\Models\CoreModule\TenantUser;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class TenantUserRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findByEmail(string $email): ?TenantUser
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('email', $email)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByTenantIdAndEmail(int $tenantId, string $email): ?TenantUser
    {
        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->with(['role', 'permission'])
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->where('is_deleted', false)
            ->first();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('is_deleted', false)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantUser
    {
        $this->requireValue($data, 'code');

        return TenantUser::query()
            ->create($data)
            ->load(['role', 'permission']);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant user {$key} is required.");
        }
    }

    public function findById(int $userId): ?TenantUser
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('id', $userId)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByCode(string $code): ?TenantUser
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('code', $code)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByIdWithLock(int $userId): ?TenantUser
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('id', $userId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?TenantUser
    {
        return TenantUser::query()
            ->with(['role', 'permission'])
            ->where('code', $code)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    public function existsByField(string $field, string $value, ?int $ignoreUserId = null): bool
    {
        $query = TenantUser::query()
            ->where('is_deleted', false)
            ->where($field, $value);

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }

    public function existsForTenant(int $tenantId, string $field, string $value, ?int $ignoreUserId = null): bool
    {
        $query = TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where($field, $value);

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }

    public function update(TenantUser $tenantUser, array $data): TenantUser
    {
        $tenantUser->update($data);

        return $tenantUser->refresh()->load(['role', 'permission']);
    }

    public function updateWithLock(TenantUser $tenantUser, array $data): TenantUser
    {
        $lockedUser = TenantUser::query()
            ->whereKey($tenantUser->getKey())
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedUser, $data);
    }

    public function reactivate(int $tenantId, int $userId): bool
    {
        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->where('is_deleted', false)
            ->where('status', 'inactive')
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]) > 0;
    }

    public function findActiveUsersAfterId(int $afterId, int $limit): Collection
    {
        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('is_deleted', false)
            ->where('status', 'active')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'tenant_id']);
    }

    public function findActiveUsersByIdsWithLock(array $userIds): Collection
    {
        if ($userIds === []) {
            return new Collection();
        }

        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($userIds)
            ->where('is_deleted', false)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get(['id', 'tenant_id']);
    }

    public function markUsersInactive(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($userIds)
            ->where('is_deleted', false)
            ->where('status', 'active')
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
    }

    public function deleteSessionsForUser(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }

    public function findOwnedByPlatformUserAndEmailWithLock(int $platformUserId, string $email): Collection
    {
        return TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('email', $email)
            ->where('is_deleted', false)
            ->whereHas('tenant', fn ($query) => $query->where('platform_user_id', $platformUserId))
            ->lockForUpdate()
            ->get();
    }

    public function updateSynchronizedPassword(TenantUser $tenantUser, string $passwordHash): TenantUser
    {
        $tenantUser->forceFill([
            'password' => $passwordHash,
            'remember_token' => null,
            'update_key' => (int) $tenantUser->update_key + 1,
        ])->save();

        return $tenantUser->refresh();
    }

    public function deletePersonalAccessTokensForUsers(array $tenantUserIds): void
    {
        if ($tenantUserIds === []) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new TenantUser())->getMorphClass())
            ->whereIn('tokenable_id', $tenantUserIds)
            ->delete();
    }

    public function sessionCandidatesForUsers(array $tenantUserIds): SupportCollection
    {
        if ($tenantUserIds === []) {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->whereIn('user_id', $tenantUserIds)
            ->get(['id', 'user_id', 'payload']);
    }

    public function deleteSessionsByIds(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }

        DB::table(config('session.table', 'sessions'))->whereIn('id', $sessionIds)->delete();
    }
}
