<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\RequestObjects\TenantUpdate;
use App\DataObjects\RequestObjects\TenantUserCreate;
use App\DataObjects\ResponseObjects\TenantListPage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\RequiredValueMissing;
use App\Exceptions\TenantAccessDenied;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\AuthService;
use App\Services\PlatformModule\PlatformUserService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\TenantUserService;
use App\Utility\MessageCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TenantManagementService extends BaseTenantService
{
    protected const TENANT_LIST_CACHE_TTL_SECONDS = 600;

    /**
     * Create a new class instance.
     */
    private TenantRepository $repository;
    private PlatformUserService $platformUserService;
    private TenantUserService $tenantUserService;
    private TenantLookupService $tenantLookupService;
    private TenantContactService $tenantContactService;
    private TenantBrandingService $tenantBrandingService;
    private TenantLicenseService $tenantLicenseService;
    private AuthService $authService;
    private TenantSettingService $tenantSettingService;
    private TableIdGenerationService $tableIdGenerationService;

    public function __construct(
        TenantRepository $repository,
        PlatformUserService $platformUserService,
        TenantUserService $tenantUserService,
        TenantLookupService $tenantLookupService,
        TenantContactService $tenantContactService,
        TenantBrandingService $tenantBrandingService,
        TenantLicenseService $tenantLicenseService,
        AuthService $authService,
        TenantSettingService $tenantSettingService,
        TableIdGenerationService $tableIdGenerationService,
    )
    {
        $this->repository = $repository;
        $this->platformUserService = $platformUserService;
        $this->tenantUserService = $tenantUserService;
        $this->tenantLookupService = $tenantLookupService;
        $this->tenantContactService = $tenantContactService;
        $this->tenantBrandingService = $tenantBrandingService;
        $this->tenantLicenseService = $tenantLicenseService;
        $this->authService = $authService;
        $this->tenantSettingService = $tenantSettingService;
        $this->tableIdGenerationService = $tableIdGenerationService;
    }

    public function createTenant(TenantCreate $request) : Tenant
    {
        $platformUser = $this->resolvePlatformUser($request);
        $platformUserId = $platformUser->id;
        $status = $this->resolveStatus($request);
        $planType = $this->resolvePlanType($request);
        $tenantCode = $this->resolveTenantCode($request);
        $request->code = $tenantCode;
        $request->subdomain = $request->createdByAdmin ? $request->subdomain : null;
        $this->ensureSubdomainIsAvailable($request->subdomain);
        $approvedBy = $request->createdByAdmin ? $this->requireAuthenticatedAdminId() : null;
        $tenant = DB::transaction(function () use (
            $request,
            $platformUserId,
            $status,
            $planType,
            $approvedBy,
            $platformUser,
            $tenantCode,
        ) {
            $tenant = $this->repository->create([
                'platform_user_id' => $platformUserId,
                'name' => $request->name,
                'tenant_code' => $tenantCode,
                'subdomain' => $request->subdomain,
                'status' => $status,
            ]);

            $request->status = $status;
            $request->planType = $planType;

            $license = $this->tenantLicenseService->createLicense(
                $tenant->id,
                $approvedBy,
                $request
            );
            $this->tenantBrandingService->createDefaultTenantBranding($tenant->id);

            $this->repository->createStatusLog([
                'tenant_id' => $tenant->id,
                'old_status' => null,
                'new_status' => $status,
                'changed_by' => $approvedBy,
                'reason' => 'Tenant created',
            ]);

            $this->tenantLicenseService->createStatusLog([
                'license_id' => $license->id,
                'old_status' => null,
                'new_status' => $status,
                'changed_by' => $approvedBy,
                'reason' => 'Tenant license created',
            ]);

            $this->tenantUserService->createOwner(new TenantUserCreate(
                tenantId: $tenant->id,
                name: $platformUser->name,
                nrc: 'OWNER-'.$tenant->id,
                phone: $platformUser->phone ?? '00000000',
                password: $platformUser->password,
                email: $platformUser->email,
            ));

            $this->tenantContactService->createContact($request, $tenant->id);
            $this->tenantSettingService->createDefaultTenantSettings($tenant->id);

            return $tenant;
        });

        $this->flushTenantListCaches($platformUserId);

        return $tenant->load('license');
    }

    public function all(): TenantListPage
    {
        $perPage = 15;
        $page = $this->resolveCurrentPage();
        $version = $this->tenantListCacheVersion();

        return Cache::remember(
            $this->tenantListCacheKey('all', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_LIST_CACHE_TTL_SECONDS),
            fn () => TenantListPage::fromPaginator($this->repository->paginateAll($perPage))
        );
    }

    public function listByPlatformUser(): TenantListPage
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $perPage = 15;
        $page = $this->resolveCurrentPage();
        $version = $this->platformUserTenantListCacheVersion($platformUser->id);

        return Cache::remember(
            $this->tenantListCacheKey('owner-'.$platformUser->id, $version, $page, $perPage),
            now()->addSeconds(self::TENANT_LIST_CACHE_TTL_SECONDS),
            fn () => TenantListPage::fromPaginator(
                $this->repository->paginateByPlatformUser($platformUser->id, $perPage)
            )
        );
    }

    public function updateTenant(TenantUpdate $request): void
    {
        $platformUser = $this->authService->getCurrentUser('platformuser');
        $tenant = $this->tenantLookupService->findById($request->tenantId);

        if ($tenant->platform_user_id !== $platformUser->id) {
            throw new TenantAccessDenied($this->responseMessage(MessageCode::NotTenantOwner));
        }
        if($tenant->update_key !== $request->updateKey)
        {
            throw new AlreadyUpdatedException("This Tenant is already updated.Please refresh");
        }

        $tenantData = $this->buildTenantUpdateData($tenant, $request);

        DB::transaction(function () use ($tenant, $request, $tenantData) {
            if ($tenantData !== []) {
                $this->repository->update($tenant, $tenantData);
            }

            if ($this->hasContactUpdates($request)) {
                $this->tenantContactService->upsertContact($request, $tenant->id);
            }

            if ($this->hasBrandingUpdates($request)) {
                $this->tenantBrandingService->upsertTenantBranding($request, $tenant->id);
            }
        });

        $this->flushTenantListCaches($tenant->platform_user_id);
    }

    protected function resolvePlatformUser(TenantCreate $request): PlatformUser
    {
        if (! $request->createdByAdmin) {
            $platformUser = $this->authService->getCurrentUser("platformuser");
            return $platformUser;
        }

        if ($request->platformUserId == null) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::TenantOwnerRequired));
        }

        $platformUser = $this->platformUserService->findById($request->platformUserId);
        return $platformUser;
    }

    protected function resolvePlanType(TenantCreate $request): string
    {
        if (! $request->createdByAdmin) {
            return 'trial';
        }

        if ($request->planType == null) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::PlanTypeRequired));
        }

        return $request->planType;
    }

    protected function resolveStatus(TenantCreate $request): string
    {
        if (! $request->createdByAdmin) {
            return 'active';
        }

        if ($request->status == null) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::TenantStatusRequired));
        }

        return $request->status;
    }

    protected function requireAuthenticatedAdminId(): int
    {
        $platformAdmin = $this->authService->getCurrentUser("platformadmin");
        return $platformAdmin->id;
    }

    protected function resolveTenantCode(TenantCreate $request): string
    {
        if ($request->createdByAdmin) {
            if ($request->code === null || trim($request->code) === '') {
                throw new RequiredValueMissing($this->responseMessage(MessageCode::TenantCodeRequired));
            }

            $this->ensureTenantCodeIsAvailable($request->code);

            return $request->code;
        }

        return $this->generateUniqueTenantCode($request->name);
    }

    protected function generateUniqueTenantCode(string $tenantName): string
    {
        $prefix = $this->tenantCodePrefix($tenantName);

        do {
            $code = $prefix.$this->tableIdGenerationService->generateTenantCodeSuffix(CarbonImmutable::now());
        } while ($this->repository->findByTenantCode($code) !== null);

        return $code;
    }

    protected function tenantCodePrefix(string $tenantName): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($tenantName), -1, PREG_SPLIT_NO_EMPTY);
        $words = $words ?: [];

        if (count($words) >= 3) {
            $prefix = '';

            foreach (array_slice($words, 0, 3) as $word) {
                $prefix .= strtoupper(substr($word, 0, 1));
            }

            return $prefix;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenantName) ?? '');

        if ($normalized === '') {
            return 'TEN';
        }

        return substr($normalized, 0, min(3, strlen($normalized)));
    }

    protected function ensureTenantCodeIsAvailable(string $code): void
    {
        if ($this->repository->findByTenantCode($code) != null) {
            throw new DuplicateValueFound($this->responseMessage(MessageCode::TenantCodeDuplicate));
        }
    }

    protected function ensureSubdomainIsAvailable(?string $subdomain): void
    {
        if ($subdomain == null) {
            return;
        }

        if ($this->repository->findBySubDomain($subdomain) != null) {
            throw new DuplicateValueFound($this->responseMessage(MessageCode::SubdomainDuplicate));
        }
    }

    protected function buildTenantUpdateData(Tenant $tenant, TenantUpdate $request): array
    {
        $data = [];
        $data['update_key'] = $tenant->update_key+1;
        if ($request->name !== null) {
            $data['name'] = $request->name;
        }

        if ($request->code !== null && $request->code !== $tenant->tenant_code) {
            $this->ensureTenantCodeIsAvailable($request->code);
            $data['tenant_code'] = $request->code;
        }

        if ($request->subdomain !== $tenant->subdomain) {
            if ($request->subdomain !== null) {
                $this->ensureSubdomainIsAvailable($request->subdomain);
            }

            $data['subdomain'] = $request->subdomain;
        }

        return $data;
    }

    protected function hasContactUpdates(TenantUpdate $request): bool
    {
        return $request->address !== null
            || $request->phone !== null
            || $request->city !== null
            || $request->country !== null;
    }

    protected function hasBrandingUpdates(TenantUpdate $request): bool
    {
        return $request->logoFile !== null
            || $request->faviconFile !== null
            || $request->logoPath !== null
            || $request->faviconPath !== null
            || $request->primaryColor !== null
            || $request->secondaryColor !== null
            || $request->accentColor !== null
            || $request->slipHeaderText !== null
            || $request->slipFooterText !== null;
    }

    protected function flushTenantListCaches(int $platformUserId): void
    {
        Cache::forever(
            $this->tenantListCacheVersionKey(),
            $this->tenantListCacheVersion() + 1
        );
        Cache::forever(
            $this->platformUserTenantListCacheVersionKey($platformUserId),
            $this->platformUserTenantListCacheVersion($platformUserId) + 1
        );
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }

    protected function tenantListCacheKey(string $scope, int $version, int $page, int $perPage): string
    {
        return "tenant-list:{$scope}:v{$version}:page:{$page}:per-page:{$perPage}";
    }

    protected function tenantListCacheVersion(): int
    {
        return (int) Cache::get($this->tenantListCacheVersionKey(), 1);
    }

    protected function platformUserTenantListCacheVersion(int $platformUserId): int
    {
        return (int) Cache::get($this->platformUserTenantListCacheVersionKey($platformUserId), 1);
    }

    protected function tenantListCacheVersionKey(): string
    {
        return 'tenant-list:version:all';
    }

    protected function platformUserTenantListCacheVersionKey(int $platformUserId): string
    {
        return "tenant-list:version:owner:{$platformUserId}";
    }


}
