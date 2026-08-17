<?php

namespace App\Repository;

use App\Models\TenantUserNotification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class TenantUserNotificationRepository
{
    public function create(array $data): TenantUserNotification
    {
        return TenantUserNotification::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::ulid(),
            ...$data,
        ])->refresh();
    }

    public function paginateForUser(int $tenantId, int $tenantUserId, int $perPage): LengthAwarePaginator
    {
        return TenantUserNotification::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tenant_user_id', $tenantUserId)
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function unreadCount(int $tenantId, int $tenantUserId): int
    {
        return TenantUserNotification::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tenant_user_id', $tenantUserId)
            ->whereNull('read_at')
            ->count();
    }

    public function findForUser(string $id, int $tenantId, int $tenantUserId): ?TenantUserNotification
    {
        return TenantUserNotification::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tenant_user_id', $tenantUserId)
            ->find($id);
    }

    public function markRead(TenantUserNotification $notification): TenantUserNotification
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->refresh();
    }

    public function markAllRead(int $tenantId, int $tenantUserId): void
    {
        TenantUserNotification::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tenant_user_id', $tenantUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function deleteCreatedBefore(CarbonInterface $cutoff): int
    {
        return TenantUserNotification::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
