<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\TenantRequestCreate;
use App\DataObjects\RequestObjects\TenantRequestPaymentSubmit;
use App\DataObjects\ResponseObjects\TenantRequestDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantNotFound;
use App\Mail\PaymentRequestReviewedMail;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantRequest;
use App\Models\PlatformModule\Package;
use App\Repository\TenantRequestRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use App\Services\TableIdGenerationService;
use App\Support\LogsServiceOperations;
use App\Utility\FileStorageUtility;
use App\Utility\MessageCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TenantRequestService extends BaseTenantService
{
    use LogsServiceOperations;

    private const STATUS_WAITING_PAYMENT = 'waiting_payment';

    private const STATUS_PENDING_APPROVAL = 'pending_approval';

    private const STATUS_ACCEPTED = 'approved';

    private const STATUS_DECLINED = 'declined';

    public const TYPE_UPGRADE = 'plan_change';

    public const TYPE_EXTENSION = 'extension';

    public function __construct(
        private TenantRequestRepository $repository,
        private TenantLookupService $tenantLookupService,
        private TenantLicenseService $tenantLicenseService,
        private PackageService $packageService,
        private AuthService $authService,
        private FileStorageUtility $fileStorageUtility,
        private TableIdGenerationService $tableIdGenerationService,
    ) {}

    public function createRequest(TenantRequestCreate $request): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): TenantRequestDetail {
            $platformUser = $this->authService->getCurrentUser('platformuser');
            $tenant = $this->resolveOwnedTenant($request->tenantId, $platformUser->id);
            $requestType = $this->normalizeRequestType($request->requestType);
            $replacedRequest = null;

            if ($requestType === self::TYPE_UPGRADE) {
                $replacedRequest = $this->resolveReplaceablePlanChange($tenant->id);
            } else {
                $this->tenantLicenseService->ensureTenantHasNoScheduledPlanTransition($tenant->id);
            }

            [$requestedPlanType, $extensionMonths, $totalCost] = $this->resolveRequestPricing($tenant, $request);

            $tenantRequest = DB::transaction(function () use (
                $platformUser,
                $tenant,
                $request,
                $requestType,
                $requestedPlanType,
                $extensionMonths,
                $totalCost,
                $replacedRequest,
            ) {
                if ($replacedRequest !== null) {
                    $this->repository->softDeleteDraftPlanChange($replacedRequest);
                }

                $tenantRequest = $this->repository->create([
                    'code' => $this->tableIdGenerationService->generateForPlatform('tenant_requests', CarbonImmutable::now()),
                    'tenant_id' => $tenant->id,
                    'requested_category_id' => $request->requestedCategoryId ?? $tenant->category_id,
                    'requested_plan_id' => $request->requestedPlanId
                        ?? $this->packageService->findByCode($requestedPlanType)->id,
                    'platform_user_id' => $platformUser->id,
                    'request_type' => $requestType,
                    'requested_plan_type' => $requestedPlanType,
                    'requested_subdomain' => $tenant->subdomain,
                    'extension_months' => $extensionMonths,
                    'total_cost' => $totalCost,
                    'currency' => $request->currency,
                    'business_info' => [
                        'tenant_code' => $tenant->tenant_code,
                        'note' => $request->note,
                        'reset_license_term' => $request->resetLicenseTermOnApproval,
                    ],
                    'request_status' => self::STATUS_WAITING_PAYMENT,
                ]);

                $this->repository->createManualPaymentRequest([
                    'code' => $this->tableIdGenerationService->generateForPlatform('manual_payment_requests', CarbonImmutable::now()),
                    'platform_user_id' => $platformUser->id,
                    'tenant_request_id' => $tenantRequest->id,
                    'tenant_id' => $tenant->id,
                    'amount' => $totalCost,
                    'currency' => $request->currency,
                    'note' => $request->note,
                    'status' => 'draft',
                ]);

                return $tenantRequest;
            });

            return TenantRequestDetail::fromModel($tenantRequest);
        });
    }

    public function createAdminApprovedGrant(
        Tenant $tenant,
        Package $plan,
        int $adminId,
        string $reason,
        ?int $extensionMonths,
        array $businessInfo,
        string $requestType = self::TYPE_UPGRADE,
    ): TenantRequest {
        return $this->repository->create([
            'code' => $this->tableIdGenerationService->generateForPlatform('tenant_requests', CarbonImmutable::now()),
            'tenant_id' => $tenant->id,
            'requested_category_id' => $plan->category_id,
            'requested_plan_id' => $plan->id,
            'platform_user_id' => $tenant->platform_user_id,
            'request_type' => $requestType,
            'requested_plan_type' => $plan->code,
            'requested_subdomain' => $tenant->subdomain,
            'extension_months' => $extensionMonths,
            'total_cost' => 0,
            'currency' => 'MMK',
            'business_info' => array_merge([
                'tenant_code' => $tenant->tenant_code,
                'admin_direct' => true,
                'free_grant' => true,
            ], $businessInfo),
            'request_status' => self::STATUS_ACCEPTED,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'admin_review_note' => $reason,
        ]);
    }

    public function submitPaymentScreenshot(TenantRequestPaymentSubmit $request): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): TenantRequestDetail {
            $platformUser = $this->authService->getCurrentUser('platformuser');
            $tenantRequest = $this->findOwnedTenantRequest($request->tenantRequestId, $platformUser->id);

            if ($tenantRequest->request_status !== self::STATUS_WAITING_PAYMENT) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::InvalidStateForPaymentSubmission));
            }
            if ($tenantRequest->update_key !== $request->updateKey) {
                throw new AlreadyUpdatedException('This Item is already updated.Please Refresh');
            }
            $newKey = $tenantRequest->update_key + 1;
            $manualPaymentRequest = $this->repository->findManualPaymentRequestByTenantRequestId($tenantRequest->id);

            if (! $manualPaymentRequest) {
                throw new TenantNotFound(null);
            }

            $paymentPath = $this->fileStorageUtility->uploadImage(
                $request->paymentScreenshot,
                'tenant-requests/'.$tenantRequest->code.'/payments',
                'local',
                'payment_screenshot'
            );

            $tenantRequest = DB::transaction(function () use ($request, $platformUser, $tenantRequest, $manualPaymentRequest, $paymentPath, $newKey) {
                $this->repository->updateManualPaymentRequest($manualPaymentRequest, [
                    'payment_reference' => $request->paymentReference,
                    'note' => $request->note ?? $manualPaymentRequest->note,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'update_key' => $manualPaymentRequest->update_key + 1,
                ]);

                $this->repository->createManualPaymentAttachment([
                    'code' => $this->tableIdGenerationService->generateForPlatform('manual_payment_attachments', CarbonImmutable::now()),
                    'manual_payment_request_id' => $manualPaymentRequest->id,
                    'file_path' => $paymentPath,
                    'file_type' => $request->paymentScreenshot->getMimeType(),
                    'uploaded_by' => $platformUser->id,
                ]);

                return $this->repository->updateTenantRequest($tenantRequest, [
                    'request_status' => self::STATUS_PENDING_APPROVAL,
                    'update_key' => $newKey,
                ]);
            });

            return TenantRequestDetail::fromModel($tenantRequest);
        });
    }

    public function acceptRequest(int $tenantRequestId, ?string $adminReviewNote = null): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($tenantRequestId, $adminReviewNote): TenantRequestDetail {
            $admin = $this->authService->getCurrentUser('platformadmin');

            return $this->acceptRequestAsAdmin($tenantRequestId, $admin, $adminReviewNote);
        });
    }

    public function acceptRequestAsAdmin(int $tenantRequestId, PlatformAdmin $admin, ?string $adminReviewNote = null): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($tenantRequestId, $admin, $adminReviewNote): TenantRequestDetail {
            $tenantRequest = $this->repository->findById($tenantRequestId);

            if (! $tenantRequest) {
                throw new TenantNotFound(null);
            }

            if ($tenantRequest->request_status !== self::STATUS_PENDING_APPROVAL) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::InvalidStateForRequestAccept));
            }

            $newKey = $tenantRequest->update_key + 1;

            $tenantRequest = DB::transaction(function () use ($tenantRequest, $admin, $adminReviewNote, $newKey) {
                $this->tenantLicenseService->applyApprovedTenantRequest($tenantRequest, $admin->id, $adminReviewNote);
                $manualPaymentRequest = $this->repository->findManualPaymentRequestByTenantRequestId($tenantRequest->id);

                if ($manualPaymentRequest) {
                    $this->repository->updateManualPaymentRequest($manualPaymentRequest, [
                        'status' => 'approved',
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                        'update_key' => $manualPaymentRequest->update_key + 1,
                    ]);
                }

                return $this->repository->updateTenantRequest($tenantRequest, [
                    'request_status' => self::STATUS_ACCEPTED,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'admin_review_note' => $adminReviewNote,
                    'update_key' => $newKey,
                ]);
            });

            $this->sendPaymentRequestReviewedMail($tenantRequest->id, 'approved');

            return TenantRequestDetail::fromModel($tenantRequest);
        });
    }

    public function declineRequest(int $tenantRequestId, ?string $adminReviewNote = null): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($tenantRequestId, $adminReviewNote): TenantRequestDetail {
            $admin = $this->authService->getCurrentUser('platformadmin');

            return $this->declineRequestAsAdmin($tenantRequestId, $admin, $adminReviewNote);
        });
    }

    public function declineRequestAsAdmin(int $tenantRequestId, PlatformAdmin $admin, ?string $adminReviewNote = null): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($tenantRequestId, $admin, $adminReviewNote): TenantRequestDetail {
            $tenantRequest = $this->repository->findById($tenantRequestId);

            if (! $tenantRequest) {
                throw new TenantNotFound(null);
            }

            if ($tenantRequest->request_status !== self::STATUS_PENDING_APPROVAL) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::InvalidStateForRequestAccept));
            }
            $newKey = $tenantRequest->update_key + 1;

            $tenantRequest = DB::transaction(function () use ($tenantRequest, $admin, $adminReviewNote, $newKey) {
                $manualPaymentRequest = $this->repository->findManualPaymentRequestByTenantRequestId($tenantRequest->id);

                if ($manualPaymentRequest) {
                    $this->repository->updateManualPaymentRequest($manualPaymentRequest, [
                        'status' => 'rejected',
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                        'update_key' => $manualPaymentRequest->update_key + 1,
                    ]);
                }

                return $this->repository->updateTenantRequest($tenantRequest, [
                    'request_status' => self::STATUS_DECLINED,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'admin_review_note' => $adminReviewNote,
                    'update_key' => $newKey,
                ]);
            });

            $this->sendPaymentRequestReviewedMail($tenantRequest->id, 'rejected');

            return TenantRequestDetail::fromModel($tenantRequest);
        });
    }

    protected function sendPaymentRequestReviewedMail(int $tenantRequestId, string $decision): void
    {
        $paymentRequest = $this->repository->findManualPaymentRequestByTenantRequestId($tenantRequestId);

        if (! $paymentRequest) {
            return;
        }

        $paymentRequest->loadMissing(['platformUser', 'tenant', 'tenantRequest']);
        $email = $paymentRequest->platformUser?->email;

        if ($email === null || $email === '') {
            return;
        }

        Mail::to($email)->send(
            (new PaymentRequestReviewedMail($paymentRequest, $decision))
                ->locale($this->mailLocaleFor($paymentRequest->platformUser?->prefer_lang))
        );
    }

    protected function mailLocaleFor(?string $locale): string
    {
        return in_array($locale, config('app.supported_locales', []), true)
            ? $locale
            : config('app.locale');
    }

    protected function findOwnedTenantRequest(int $tenantRequestId, int $platformUserId)
    {
        $tenantRequest = $this->repository->findById($tenantRequestId);

        if (! $tenantRequest) {
            throw new TenantNotFound(null);
        }

        if ($tenantRequest->platform_user_id !== $platformUserId) {
            throw new TenantAccessDenied($this->responseMessage(MessageCode::NotTenantOwner));
        }

        return $tenantRequest;
    }

    protected function resolveOwnedTenant(int $tenantId, int $platformUserId): Tenant
    {
        $tenant = $this->tenantLookupService->findById($tenantId);

        if ($tenant->platform_user_id !== $platformUserId) {
            throw new TenantAccessDenied($this->responseMessage(MessageCode::NotTenantOwner));
        }

        return $tenant;
    }

    protected function normalizeRequestType(string $requestType): string
    {
        $requestType = strtolower($requestType);

        if (! in_array($requestType, [self::TYPE_UPGRADE, self::TYPE_EXTENSION], true)) {
            throw new InvalidTenantRequest;
        }

        return $requestType;
    }

    protected function resolveRequestPricing(Tenant $tenant, TenantRequestCreate $request): array
    {
        $requestType = $this->normalizeRequestType($request->requestType);

        if ($requestType === self::TYPE_UPGRADE) {
            if ($request->requestedPlanType == null && $request->requestedPlanId === null) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::InvalidPackageUpgrade));
            }

            $package = $request->requestedPlanId !== null
                ? $this->packageService->findActiveById($request->requestedPlanId)
                : $this->packageService->findActiveByCode((string) $request->requestedPlanType);
            if ($package->is_trial || ($request->requestedCategoryId !== null && (int) $package->category_id !== $request->requestedCategoryId)) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::InvalidPackageUpgrade));
            }
            $request->requestedPlanType = $package->code;
            $request->requestedPlanId = $package->id;
            $request->requestedCategoryId = $package->category_id;
            $currentLicense = $this->tenantLicenseService->getTenantLicense($tenant->id);
            $currentPlanType = $currentLicense->plan?->code ?? $currentLicense->plan_type;

            if ($request->requestedPlanType === $currentPlanType) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::SamePackageUpgrade));
            }

            if ($request->resetLicenseTermOnApproval) {
                $months = $this->validateExtensionMonths($request->extensionMonths);
                return [$package->code, $months, $this->discountedPackageCost($package, $months)];
            }

            if (($package->rank ?? 0) < ($currentLicense->plan?->rank ?? 0)) {
                return [
                    $request->requestedPlanType,
                    $this->validateExtensionMonths($request->extensionMonths),
                    $this->discountedPackageCost($package, $this->validateExtensionMonths($request->extensionMonths)),
                ];
            }

            $billingMonths = $this->monthsUntilLicenseExpiry($tenant);

            return [
                $request->requestedPlanType,
                null,
                round((float) $package->price * $billingMonths, 2),
            ];
        }

        $currentLicense = $this->tenantLicenseService->getTenantLicense($tenant->id);
        $currentPlanType = $currentLicense->plan?->code ?? $currentLicense->plan_type;

        if ($currentLicense->plan?->is_trial || $currentPlanType === 'trial') {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::UnsupportedPackageType));
        }

        $package = $this->packageService->findActiveByCode($currentPlanType);
        $extensionMonths = $this->validateExtensionMonths($request->extensionMonths);
        $totalCost = $this->discountedPackageCost($package, $extensionMonths);

        return [
            $currentPlanType,
            $extensionMonths,
            $totalCost,
        ];
    }

    protected function validateExtensionMonths(?int $extensionMonths): int
    {
        if ($extensionMonths === null || ! isset(config('pricing.extension_discounts', [])[$extensionMonths])) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::ExtensionMonthRequired));
        }

        return $extensionMonths;
    }

    protected function discountedPackageCost($package, int $months): float
    {
        $discountRate = (float) config('pricing.extension_discounts', [])[$months];

        return round((float) $package->price * $months * (1 - $discountRate), 2);
    }

    protected function resolveReplaceablePlanChange(int $tenantId): ?TenantRequest
    {
        $existingRequest = $this->repository->findOpenPlanChangeByTenantId($tenantId);

        if ($existingRequest === null) {
            return null;
        }

        if ($existingRequest->request_status !== self::STATUS_WAITING_PAYMENT) {
            throw new InvalidTenantRequest('Resolve the existing plan change request before creating another one.');
        }

        return $existingRequest;
    }

    protected function monthsUntilLicenseExpiry(Tenant $tenant): int
    {
        $license = $this->tenantLicenseService->getTenantLicense($tenant->id);

        if ($license->expires_at === null || $license->expires_at->lte(now())) {
            return 1;
        }

        $monthsUntilExpiry = now()->startOfDay()->diffInMonths($license->expires_at->copy()->startOfDay());
        Log::info("Tenant {$tenant->id} license expires in {$monthsUntilExpiry} months.");
        return max(1, (int) ($monthsUntilExpiry));
    }
}
