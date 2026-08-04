<?php

namespace App\Repository;

use App\Models\CoreModule\TenantUser;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function deleteSessionsForUser(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
