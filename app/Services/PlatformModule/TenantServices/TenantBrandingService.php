<?php

namespace App\Services\PlatformModule\TenantServices;

use App\Exceptions\DuplicateValueFound;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantNotFound;
use App\DataObjects\RequestObjects\TenantBrandingUpdate;
use App\Models\CoreModule\TenantBranding;
use App\Models\PlatformModule\Tenant;
use App\DataObjects\RequestObjects\TenantUpdate;
use App\DataObjects\ResponseObjects\TenantBrandingDetail;
use App\Repository\TenantBrandingRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\SlipDocumentLayoutValidator;
use App\Services\PlatformModule\AuthService;
use App\Services\TenantModule\TenantBrandingSlipLayoutService;
use App\Utility\FileStorageUtility;
use Illuminate\Http\UploadedFile;

class TenantBrandingService extends BaseTenantService
{
    protected const FEATURE_TENANT_BRANDING = 'branding_management';

    public function __construct(
        private TenantLicenseService $tenantLicenseService,
        private TenantLookupService $tenantLookupService,
        private TenantBrandingRepository $repository,
        private AuthService $authService,
        private FileStorageUtility $fileStorageUtility,
        private SlipDocumentLayoutValidator $slipDocumentLayoutValidator,
    ) {
    }

    public function createTenantBranding(array $data, ?int $tenantId = null): TenantBranding
    {
        $tenantId = $this->resolveTargetTenantId($tenantId);

        $this->tenantLicenseService->ensureTenantHasFeature($tenantId, self::FEATURE_TENANT_BRANDING);

        if ($this->repository->isBrandingExisted($tenantId)) {
            throw new DuplicateValueFound('Tenant branding already exists.');
        }

        $brandingDirectory = 'tenants/'.$tenantId.'/branding';

        return $this->repository->create([
            'tenant_id' => $tenantId,
            'tenant_code' => $this->resolveTenantCode($tenantId),
            'logo_path' => $this->resolveImagePath($data, 'logo_file', 'logo_path', $brandingDirectory, 'logo'),
            'favicon_path' => $this->resolveImagePath($data, 'favicon_file', 'favicon_path', $brandingDirectory, 'favicon'),
            'primary_color' => $data['primary_color'] ?? null,
            'secondary_color' => $data['secondary_color'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
        ]);
    }

    public function createDefaultTenantBranding(int $tenantId): TenantBranding
    {
        return $this->repository->create([
            'tenant_id' => $tenantId,
            'tenant_code' => $this->resolveTenantCode($tenantId),
            'logo_path' => null,
            'favicon_path' => null,
            'primary_color' => config('branding.primary_color'),
            'secondary_color' => config('branding.secondary_color'),
            'accent_color' => config('branding.accent_color'),
            'slip_header_layout' => $this->slipDocumentLayoutValidator->defaultHeaderLayout(),
            'slip_footer_layout' => $this->slipDocumentLayoutValidator->defaultFooterLayout(),
        ]);
    }

    public function upsertTenantBranding(TenantUpdate $request, int $tenantId): TenantBranding
    {
        $tenantId = $this->resolveTargetTenantId($tenantId);
        $this->tenantLicenseService->ensureTenantHasFeature($tenantId, self::FEATURE_TENANT_BRANDING);

        $brandingDirectory = 'tenants/'.$tenantId.'/branding';
        $data = [];

        if ($request->logoFile !== null || $request->logoPath !== null) {
            $data['logo_path'] = $this->resolveImagePath([
                'logo_file' => $request->logoFile,
                'logo_path' => $request->logoPath,
            ], 'logo_file', 'logo_path', $brandingDirectory, 'logo');
        }

        if ($request->faviconFile !== null || $request->faviconPath !== null) {
            $data['favicon_path'] = $this->resolveImagePath([
                'favicon_file' => $request->faviconFile,
                'favicon_path' => $request->faviconPath,
            ], 'favicon_file', 'favicon_path', $brandingDirectory, 'favicon');
        }

        if ($request->primaryColor !== null) {
            $data['primary_color'] = $request->primaryColor;
        }

        if ($request->secondaryColor !== null) {
            $data['secondary_color'] = $request->secondaryColor;
        }

        if ($request->accentColor !== null) {
            $data['accent_color'] = $request->accentColor;
        }

        $branding = $this->repository->findByTenantId($tenantId);

        if ($branding == null) {
            return $this->repository->create([
                'tenant_id' => $tenantId,
                'tenant_code' => $this->resolveTenantCode($tenantId),
                ...$data,
            ]);
        }

        if ($data === []) {
            return $branding;
        }

        return $this->repository->update($branding, $data);
    }

    public function updateCurrentTenantBranding(TenantBrandingUpdate $request): TenantBrandingDetail
    {
        $tenantId = $this->resolveCurrentTenantId();
        $branding = $this->repository->firstOrCreateForTenant(
            $tenantId,
            [
                'tenant_code' => $this->resolveCurrentTenantCode(),
            ],
        );

        if ((int) $branding->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This branding is already updated. Please refresh to see the update.');
        }

        $data = [
            'tenant_code' => $this->resolveCurrentTenantCode(),
            'update_key' => $branding->update_key + 1,
        ];

        if ($request->primaryColor !== null) {
            $data['primary_color'] = $request->primaryColor;
        }

        if ($request->secondaryColor !== null) {
            $data['secondary_color'] = $request->secondaryColor;
        }

        if ($request->accentColor !== null) {
            $data['accent_color'] = $request->accentColor;
        }

        return TenantBrandingDetail::fromModel($this->repository->update($branding, $data));
    }

    public function getCurrentTenantBranding(): TenantBrandingDetail
    {
        $tenantId = $this->resolveCurrentTenantId();

        return TenantBrandingDetail::fromModel($this->repository->firstOrCreateForTenant(
            $tenantId,
            [
                'tenant_code' => $this->resolveCurrentTenantCode(),
            ],
        ));
    }

    public function findByTenantId(int $tenantId): ?TenantBranding
    {
        $res = $this->repository->findByTenantId($tenantId);
        if(!$res)
        {
            throw new TenantNotFound("Tenant Branding Not Found");
        }
        return $res;
    }

    protected function resolveImagePath(
        array $data,
        string $fileKey,
        string $pathKey,
        string $directory,
        string $fileNamePrefix
    ): ?string {
        $file = $data[$fileKey] ?? null;

        if ($file instanceof UploadedFile) {
            return $this->fileStorageUtility->uploadImage($file, $directory, 'public', $fileNamePrefix);
        }

        return $data[$pathKey] ?? null;
    }

    protected function resolveTargetTenantId(?int $tenantId): int
    {
        if ($tenantId === null) {
            return $this->resolveCurrentTenantId();
        }

        return $this->authorizePlatformUserTenant($tenantId);
    }

    protected function resolveTenantCode(int $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFound('Tenant not found.');
        }

        return $tenant->tenant_code;
    }

    protected function authorizePlatformUserTenant(int $tenantId): int
    {
        $platformUser = $this->authService->getCurrentUser(null);

        $tenant = $this->tenantLookupService->findById($tenantId);

        if ($tenant->platform_user_id !== $platformUser->id) {
            throw new TenantAccessDenied();
        }

        return $tenant->id;
    }
}
