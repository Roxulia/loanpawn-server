<?php

namespace App\Services\PlatformModule;

use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PlatformTenantPageService
{
    public function __construct(
        private AuthService $authService,
        private TenantRepository $tenantRepository,
        private PackageService $packageService,
    ) {
    }

    public function getTenantList(): LengthAwarePaginator
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');

        return $this->tenantRepository->paginateByPlatformUser($platformUser->id);
    }

    public function findOwnedTenant(int $tenantId): Tenant
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $tenant = $this->tenantRepository->findByIdForPlatformUser($tenantId, $platformUser->id);

        if ($tenant === null) {
            throw new TenantNotFound(null);
        }

        return $tenant;
    }

    public function activePaidPlansExcept(?string $excludedCode = null)
    {
        return $this->packageService->activePaidPackagesExcept($excludedCode);
    }
}
