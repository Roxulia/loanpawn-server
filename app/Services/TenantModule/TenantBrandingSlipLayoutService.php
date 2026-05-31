<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantBrandingSlipLayoutUpdate;
use App\DataObjects\ResponseObjects\TenantBrandingDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Models\CoreModule\TenantBranding;
use App\Repository\TenantBrandingRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\SlipDocumentLayoutValidator;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Illuminate\Support\Facades\DB;

class TenantBrandingSlipLayoutService extends BaseTenantService
{
    protected const FEATURE_SLIP_DOCUMENT_LAYOUT_MANAGEMENT = 'slip_document_layout_management';

    public function __construct(
        private TenantBrandingRepository $repository,
        private SlipDocumentLayoutValidator $layoutValidator,
        private TenantUserPermissionService $permissionService,
        private TenantLicenseService $tenantLicenseService,
    ) {
    }

    public function getCurrentTenantLayouts(): TenantBrandingDetail
    {
        $this->permissionService->authorizeLoanContractList();

        $branding = $this->repository->findByTenantId($this->resolveCurrentTenantId());

        if ($branding === null) {
            $branding = $this->repository->create([
                'tenant_id' => $this->resolveCurrentTenantId(),
                'tenant_code' => $this->resolveCurrentTenantCode(),
                'slip_header_layout' => $this->layoutValidator->defaultHeaderLayout(),
                'slip_footer_layout' => $this->layoutValidator->defaultFooterLayout(),
            ]);
        }

        return TenantBrandingDetail::fromModel($this->normalizePersistedLayouts($branding));
    }

    public function updateCurrentTenantLayouts(TenantBrandingSlipLayoutUpdate $request): TenantBrandingDetail
    {
        $this->permissionService->authorizeSlipDocumentManage();
        $this->tenantLicenseService->ensureCurrentTenantHasFeature(self::FEATURE_SLIP_DOCUMENT_LAYOUT_MANAGEMENT);
        $tenantId = $this->resolveCurrentTenantId();
        $data = [];

        if ($request->slipHeaderLayout !== null) {
            $data['slip_header_layout'] = $this->layoutValidator->normalizeLayout($request->slipHeaderLayout, 'header');
        }

        if ($request->slipFooterLayout !== null) {
            $data['slip_footer_layout'] = $this->layoutValidator->normalizeLayout($request->slipFooterLayout, 'footer');
        }

        $branding = DB::transaction(function () use ($tenantId, $data,$request): TenantBranding {
            $branding = $this->repository->findByTenantIdWithLock($tenantId);

            if ($branding === null) {
                return $this->repository->create([
                    'tenant_id' => $tenantId,
                    'tenant_code' => $this->resolveCurrentTenantCode(),
                    'slip_header_layout' => $data['slip_header_layout'] ?? $this->layoutValidator->defaultHeaderLayout(),
                    'slip_footer_layout' => $data['slip_footer_layout'] ?? $this->layoutValidator->defaultFooterLayout(),
                ]);
            }

            if ($data === []) {
                return $this->normalizePersistedLayouts($branding);
            }
            if($branding->update_key !== $request->updateKey)
            {
                throw new AlreadyUpdatedException("This item is already Updated.Please refresh");
            }
            $data['update_key'] = $branding->update_key+1;

            return $this->repository->update($branding, $data);
        });

        return TenantBrandingDetail::fromModel($branding);
    }

    public function getCurrentTenantBrandingModel(): TenantBranding
    {
        $branding = $this->repository->findByTenantId($this->resolveCurrentTenantId());

        if ($branding === null) {
            return $this->repository->create([
                'tenant_id' => $this->resolveCurrentTenantId(),
                'tenant_code' => $this->resolveCurrentTenantCode(),
                'slip_header_layout' => $this->layoutValidator->defaultHeaderLayout(),
                'slip_footer_layout' => $this->layoutValidator->defaultFooterLayout(),
            ]);
        }

        return $this->normalizePersistedLayouts($branding);
    }

    protected function normalizePersistedLayouts(TenantBranding $branding): TenantBranding
    {
        $updates = [];

        if ($branding->slip_header_layout === null) {
            $updates['slip_header_layout'] = $this->layoutValidator->defaultHeaderLayout();
        }

        if ($branding->slip_footer_layout === null) {
            $updates['slip_footer_layout'] = $this->layoutValidator->defaultFooterLayout();
        }

        return $updates === []
            ? $branding
            : $this->repository->update($branding, $updates);
    }
}
