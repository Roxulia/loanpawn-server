<?php

namespace App\Support;

use App\Models\CoreModule\TenantUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TenantUserActivityCache
{
    public function __construct(
        private RedisAvailability $redisAvailability,
    ) {}

    public function remember(TenantUser $user): void
    {
        $this->store()->put(
            $this->key((int) $user->tenant_id, (int) $user->id),
            true,
            now()->addMinutes(max(1, (int) config('session.lifetime', 120))),
        );
    }

    public function forget(TenantUser $user): void
    {
        $this->store()->forget($this->key((int) $user->tenant_id, (int) $user->id));
    }

    public function has(TenantUser $user): bool
    {
        return $this->store()->has($this->key((int) $user->tenant_id, (int) $user->id));
    }

    public function missingUserIds(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $userIdByKey = $users->mapWithKeys(fn (TenantUser $user): array => [
            $this->key((int) $user->tenant_id, (int) $user->id) => (int) $user->id,
        ])->all();
        $activity = $this->store()->many(array_keys($userIdByKey));

        return collect($userIdByKey)
            ->filter(fn (int $userId, string $key): bool => ! ($activity[$key] ?? false))
            ->values()
            ->all();
    }

    protected function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store($this->redisAvailability->selectedCacheStore());
    }

    protected function key(int $tenantId, int $userId): string
    {
        return "tenant-user-activity:tenant:{$tenantId}:user:{$userId}";
    }
}
