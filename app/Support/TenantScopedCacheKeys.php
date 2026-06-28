<?php

namespace App\Support;

use App\Exceptions\TenantNotFound;
use Illuminate\Support\Facades\Cache;

class TenantScopedCacheKeys
{
    public function __construct(
        private RedisAvailability $redisAvailability,
    )
    {
        //
    }

    public function paginatedListKey(string $prefix, int $version, int $page, int $perPage, ?int $tenantId = null): string
    {
        $tenantId = $this->resolveTenantId($tenantId);

        return "{$prefix}:tenant:{$tenantId}:v{$version}:page:{$page}:per-page:{$perPage}";
    }

    public function listKey(string $prefix, int $version, ?int $tenantId = null): string
    {
        $tenantId = $this->resolveTenantId($tenantId);

        return "{$prefix}:tenant:{$tenantId}:v{$version}:list";
    }

    public function itemKey(string $prefix, string $code, int $version, ?int $tenantId = null): string
    {
        $tenantId = $this->resolveTenantId($tenantId);

        return "{$prefix}:tenant:{$tenantId}:v{$version}:item:{$code}";
    }

    public function versionKey(string $prefix, ?int $tenantId = null): string
    {
        $tenantId = $this->resolveTenantId($tenantId);

        return "{$prefix}:version:tenant:{$tenantId}";
    }

    public function currentVersion(string $prefix, int $default = 1, ?int $tenantId = null): int
    {
        $this->configureDefaultCacheStore();

        return (int) Cache::get($this->versionKey($prefix, $tenantId), $default);
    }

    public function bumpVersion(string $prefix, int $default = 1, ?int $tenantId = null): int
    {
        $this->configureDefaultCacheStore();

        $tenantId = $this->resolveTenantId($tenantId);
        $nextVersion = $this->currentVersion($prefix, $default, $tenantId) + 1;

        Cache::forever($this->versionKey($prefix, $tenantId), $nextVersion);

        return $nextVersion;
    }

    public function bumpTenantVersion(string $prefix, ?int $tenantId = null): int
    {
        $tenantId = $this->resolveTenantId($tenantId);

        return $this->bumpVersion($prefix, 1, $tenantId);
    }

    public function bumpGlobalVersion(string $prefix): int
    {
        return $this->bumpVersion($prefix, 1, null);
    }

    public function configureDefaultCacheStore(): void
    {
        Cache::setDefaultDriver($this->redisAvailability->selectedCacheStore());
    }

    protected function resolveTenantId(?int $tenantId = null): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);
        $resolvedTenantId = $tenantContext->id();

        if ($resolvedTenantId === null) {
            throw new TenantNotFound('Current tenant is not resolved.');
        }

        return $resolvedTenantId;
    }
}
