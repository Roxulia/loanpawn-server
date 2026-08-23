<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\PlatformUser;
use App\Repository\TenantUserRepository;
use App\Support\TenantScopedCacheKeys;
use App\Support\TenantUserActivityCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OwnedTenantUserCredentialSyncService
{
    public function __construct(
        private TenantUserRepository $repository,
        private TenantScopedCacheKeys $cacheKeys,
        private TenantUserActivityCache $activityCache,
    ) {}

    public function synchronize(PlatformUser $platformUser, string $passwordHash): Collection
    {
        $tenantUsers = $this->repository->findOwnedByPlatformUserAndEmailWithLock(
            (int) $platformUser->id,
            $platformUser->email,
        );

        foreach ($tenantUsers as $tenantUser) {
            $this->repository->updateSynchronizedPassword($tenantUser, $passwordHash);
        }

        $tenantUserIds = $tenantUsers->modelKeys();
        $this->repository->deletePersonalAccessTokensForUsers($tenantUserIds);
        $this->revokeDatabaseSessions($tenantUserIds);
        $this->forgetCachesAfterCommit($tenantUsers);

        return $tenantUsers;
    }

    private function revokeDatabaseSessions(array $tenantUserIds): void
    {
        if (config('session.driver') !== 'database' || $tenantUserIds === []) {
            return;
        }

        $guardSessionKey = Auth::guard('tenantuser')->getName();
        $sessionIds = $this->repository->sessionCandidatesForUsers($tenantUserIds)
            ->filter(function (object $session) use ($guardSessionKey): bool {
                $attributes = $this->decodeSessionPayload((string) $session->payload);

                return isset($attributes[$guardSessionKey])
                    && (string) $attributes[$guardSessionKey] === (string) $session->user_id;
            })
            ->pluck('id')
            ->all();

        $this->repository->deleteSessionsByIds($sessionIds);
    }

    private function decodeSessionPayload(string $payload): array
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return [];
        }

        if (config('session.serialization', 'php') === 'json') {
            $attributes = json_decode($decoded, true);

            return is_array($attributes) ? $attributes : [];
        }

        $attributes = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($attributes) ? $attributes : [];
    }

    private function forgetCachesAfterCommit(Collection $tenantUsers): void
    {
        $tenantIds = $tenantUsers->pluck('tenant_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        DB::afterCommit(function () use ($tenantUsers, $tenantIds): void {
            foreach ($tenantUsers as $tenantUser) {
                $this->activityCache->forget($tenantUser);
            }

            foreach ($tenantIds as $tenantId) {
                $this->cacheKeys->bumpVersion('tenant-user-list', tenantId: $tenantId);
            }
        });
    }
}
