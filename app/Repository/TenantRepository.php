<?php

namespace App\Repository;

use App\Models\PlatformModule\TenantStatusLog;
use App\Models\PlatformModule\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function create(array $data): Tenant
    {
        return Tenant::query()->create($data);
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->refresh();
    }

    public function createStatusLog(array $data): TenantStatusLog
    {
        return TenantStatusLog::query()->create($data);
    }

    public function findBySubDomain(string $subDomain) : ?Tenant
    {
        $res = Tenant::query()->where('subdomain',$subDomain)->first();
        return $res;
    }

    public function findByTenantCode(string $code) : ?Tenant
    {
        $res = Tenant::query()->where('tenant_code',$code)->first();
        return $res;
    }

    public function findByLicenseKey(string $licenseKey): ?Tenant
    {
        return Tenant::query()
            ->select('tenants.*')
            ->join('tenant_licenses', 'tenant_licenses.tenant_id', '=', 'tenants.id')
            ->where('tenant_licenses.license_key', $licenseKey)
            ->first();
    }

    public function findBySubDomainAndTenantCode(string $subDomain, string $code): ?Tenant
    {
        $res = Tenant::query()
            ->where('subdomain', $subDomain)
            ->where('tenant_code', $code)
            ->first();
        return $res;
    }

    public function findById(int $id) : ?Tenant
    {
        $res = Tenant::query()->where('id',$id)->first();
        return $res;
    }

    public function findByIdForPlatformUser(int $tenantId, int $platformUserId): ?Tenant
    {
        return Tenant::query()
            ->with(['license.scheduledPlanTransition', 'branding', 'contact', 'settings'])
            ->where('id', $tenantId)
            ->where('platform_user_id', $platformUserId)
            ->first();
    }

    public function paginateAll(int $perPage = 15): LengthAwarePaginator
    {
        return Tenant::query()
            ->with(['owner', 'category', 'license.plan'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginateByPlatformUser(int $platformUserId, int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with(['owner', 'category', 'license.plan', 'branding', 'contact'])
            ->where('platform_user_id', $platformUserId);

        $search = trim((string) $search);

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('tenant_code', 'like', '%'.$search.'%')
                    ->orWhere('subdomain', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhereHas('license', function ($query) use ($search) {
                        $query->where('plan_type', 'like', '%'.$search.'%')
                            ->orWhereHas('plan', fn ($planQuery) => $planQuery->where('name', 'like', '%'.$search.'%'));
                    });
            });
        }

        return $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function countByPlatformUser(int $platformUserId): int
    {
        return Tenant::query()
            ->where('platform_user_id', $platformUserId)
            ->count();
    }

    public function countActiveByPlatformUser(int $platformUserId): int
    {
        return Tenant::query()
            ->where('platform_user_id', $platformUserId)
            ->where('status', 'active')
            ->count();
    }

    public function countExpiringLicensesByPlatformUser(int $platformUserId, int $days): int
    {
        return Tenant::query()
            ->where('platform_user_id', $platformUserId)
            ->whereHas('license', function ($query) use ($days) {
                $query->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays($days)]);
            })
            ->count();
    }

    public function countResourceConfiguredTenantsByPlatformUser(int $platformUserId): int
    {
        return Tenant::query()
            ->where('platform_user_id', $platformUserId)
            ->where(function ($query) {
                $query->whereHas('branding')
                    ->orWhereHas('contact')
                    ->orWhereHas('settings');
            })
            ->count();
    }

    public function topPerformingByPlatformUser(int $platformUserId, int $limit = 5): Collection
    {
        return Tenant::query()
            ->with(['license', 'branding', 'contact'])
            ->withCount(['settings'])
            ->where('platform_user_id', $platformUserId)
            ->orderByDesc('settings_count')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
