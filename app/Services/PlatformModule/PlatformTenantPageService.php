<?php

namespace App\Services\PlatformModule;

use App\Exceptions\TenantAccessDenied;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantRepository;
use App\Utility\MessageCodes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PlatformTenantPageService
{
    public function __construct(
        private AuthService $authService,
        private TenantRepository $tenantRepository,
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
            throw new TenantAccessDenied(MessageCodes::$messages['eb018']);
        }

        return $tenant;
    }
}
