<?php

namespace App\Services\PlatformModule;

use App\Repository\ManualPaymentRequestRepository;
use App\Repository\TenantRepository;

class PlatformDashboardService
{
    public function __construct(
        private AuthService $authService,
        private TenantRepository $tenantRepository,
        private ManualPaymentRequestRepository $paymentRequestRepository,
    ) {
    }

    public function getSummary(): array
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $tenantCount = $this->tenantRepository->countByPlatformUser($platformUser->id);
        $configuredTenantCount = $this->tenantRepository->countResourceConfiguredTenantsByPlatformUser($platformUser->id);

        return [
            'tenant_count' => $tenantCount,
            'active_tenant_count' => $this->tenantRepository->countActiveByPlatformUser($platformUser->id),
            'expiring_license_count' => $this->tenantRepository->countExpiringLicensesByPlatformUser($platformUser->id, 30),
            'pending_payment_count' => $this->paymentRequestRepository->countPendingByPlatformUser($platformUser->id),
            'resource_usage_percent' => $tenantCount > 0
                ? (int) round(($configuredTenantCount / $tenantCount) * 100)
                : 0,
            'top_tenants' => $this->tenantRepository->topPerformingByPlatformUser($platformUser->id),
            'has_data' => $tenantCount > 0,
        ];
    }
}
