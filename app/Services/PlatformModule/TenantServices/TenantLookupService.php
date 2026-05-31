<?php

namespace App\Services\PlatformModule\TenantServices;

use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantRepository;

class TenantLookupService
{
    public function __construct(
        private TenantRepository $repository,
    ) {
    }

    public function findById(int $id): Tenant
    {
        $tenant = $this->repository->findById($id);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }

    public function findBySubDomain(string $subDomain): Tenant
    {
        $tenant = $this->repository->findBySubDomain($subDomain);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }

    public function findByTenantCode(string $code): Tenant
    {
        $tenant = $this->repository->findByTenantCode($code);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }

    public function findByLicenseKey(string $licenseKey): Tenant
    {
        $tenant = $this->repository->findByLicenseKey($licenseKey);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }

    public function findBySubDomainAndTenantCode(string $subDomain, string $code): Tenant
    {
        $tenant = $this->repository->findBySubDomainAndTenantCode($subDomain, $code);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }
}
