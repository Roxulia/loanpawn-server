<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\ResponseObjects\TenantDetail;
use App\DataObjects\ResponseObjects\TenantFeatures;
use App\Exceptions\StoredFileNotFound;
use App\Exceptions\TenantNotFound;
use App\Repository\TenantDetailRepository;
use App\Services\PlatformModule\PackageService;
use App\Utility\FileStorageUtility;
use App\Support\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantDetailService
{
    public function __construct(
        private TenantDetailRepository $repository,
        private TenantLookupService $tenantLookupService,
        private TenantBrandingService $tenantBrandingService,
        private FileStorageUtility $fileStorageUtility,
        private PackageService $packageService,
    ) {
    }

    public function getCurrentTenant() : TenantDetail
    {
        $tenant = app(TenantContext::class);
        $tenantId = $tenant->id();
        return $this->findByTenantId($tenantId);
    }

    public function findByTenantId(int $tenantId): TenantDetail
    {
        $tenantDetail = $this->repository->findByTenantId($tenantId);

        if ($tenantDetail === null) {
            throw new TenantNotFound(null);
        }

        return $this->prepareTenantDetail($tenantDetail);
    }

    public function findByTenantCode(string $tenantCode): TenantDetail
    {
        $tenantDetail = $this->repository->findByTenantCode($tenantCode);

        if ($tenantDetail === null) {
            throw new TenantNotFound(null);
        }

        return $this->prepareTenantDetail($tenantDetail);
    }

    public function findBySubdomain(string $subdomain): TenantDetail
    {
        $tenantDetail = $this->repository->findBySubdomain($subdomain);

        if ($tenantDetail === null) {
            throw new TenantNotFound(null);
        }

        return $this->prepareTenantDetail($tenantDetail);
    }

    public function getTenantLogoImage(string $tenantCode): StreamedResponse
    {
        $tenant = $this->tenantLookupService->findByTenantCode($tenantCode);
        $branding = $this->tenantBrandingService->findByTenantId($tenant->id);

        if ($branding === null || $branding->logo_path === null) {
            throw new StoredFileNotFound();
        }

        return $this->fileStorageUtility->retrieveImage($branding->logo_path, 'public');
    }

    protected function attachBrandingAssetUrls(TenantDetail $tenantDetail): TenantDetail
    {
        if ($tenantDetail->tenant_branding?->logoPath !== null) {
            $tenantDetail->tenant_branding->logoPath = route('api.tenant.logo.show', [
                'tenantCode' => $tenantDetail->code,
            ]);
        }

        return $tenantDetail;
    }

    protected function attachTenantFeatures(TenantDetail $tenantDetail): TenantDetail
    {
        $tenantDetail->tenant_features = new TenantFeatures(
            $this->packageService->featureFlagsByPlan($tenantDetail->tenant_license->planType)
        );

        return $tenantDetail;
    }

    protected function prepareTenantDetail(TenantDetail $tenantDetail): TenantDetail
    {
        return $this->attachTenantFeatures(
            $this->attachBrandingAssetUrls($tenantDetail)
        );
    }
}
