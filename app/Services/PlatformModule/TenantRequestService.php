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
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantRequestRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use App\Services\TableIdGenerationService;
use App\Support\LogsServiceOperations;
use App\Utility\FileStorageUtility;
use App\Utility\MessageCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TenantRequestService
{
    use LogsServiceOperations;

    private const STATUS_WAITING_PAYMENT = 'waiting_payment';

    private const STATUS_PENDING_APPROVAL = 'pending_approval';

    private const STATUS_ACCEPTED = 'approved';

    private const STATUS_DECLINED = 'declined';

    private const TYPE_UPGRADE = 'plan_change';

    private const TYPE_EXTENSION = 'extension';

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

            [$requestedPlanType, $extensionMonths, $totalCost] = $this->resolveRequestPricing($tenant, $request);

            $tenantRequest = DB::transaction(function () use (
                $platformUser,
                $tenant,
                $request,
                $requestType,
                $requestedPlanType,
                $extensionMonths,
                $totalCost,
            ) {
                $tenantRequest = $this->repository->create([
                    'code' => $this->tableIdGenerationService->generateForPlatform('tenant_requests', CarbonImmutable::now()),
                    'tenant_id' => $tenant->id,
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

    public function submitPaymentScreenshot(TenantRequestPaymentSubmit $request): TenantRequestDetail
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): TenantRequestDetail {
            $platformUser = $this->authService->getCurrentUser('platformuser');
            $tenantRequest = $this->findOwnedTenantRequest($request->tenantRequestId, $platformUser->id);

            if ($tenantRequest->request_status !== self::STATUS_WAITING_PAYMENT) {
                throw new InvalidTenantRequest(MessageCodes::$messages['eb022']);
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
                'public',
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

            $tenantRequest = $this->repository->findById($tenantRequestId);

            if (! $tenantRequest) {
                throw new TenantNotFound(null);
            }

            if ($tenantRequest->request_status !== self::STATUS_PENDING_APPROVAL) {
                throw new InvalidTenantRequest(MessageCodes::$messages['eb023']);
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

            $tenantRequest = $this->repository->findById($tenantRequestId);

            if (! $tenantRequest) {
                throw new TenantNotFound(null);
            }

            if ($tenantRequest->request_status !== self::STATUS_PENDING_APPROVAL) {
                throw new InvalidTenantRequest(MessageCodes::$messages['eb023']);
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

        Mail::to($email)->send(new PaymentRequestReviewedMail($paymentRequest, $decision));
    }

    protected function findOwnedTenantRequest(int $tenantRequestId, int $platformUserId)
    {
        $tenantRequest = $this->repository->findById($tenantRequestId);

        if (! $tenantRequest) {
            throw new TenantNotFound(null);
        }

        if ($tenantRequest->platform_user_id !== $platformUserId) {
            throw new TenantAccessDenied(MessageCodes::$messages['eb018']);
        }

        return $tenantRequest;
    }

    protected function resolveOwnedTenant(int $tenantId, int $platformUserId): Tenant
    {
        $tenant = $this->tenantLookupService->findById($tenantId);

        if ($tenant->platform_user_id !== $platformUserId) {
            throw new TenantAccessDenied(MessageCodes::$messages['eb018']);
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
            if ($request->requestedPlanType == null || ! in_array($request->requestedPlanType, ['basic', 'premium'], true)) {
                throw new InvalidTenantRequest(MessageCodes::$messages['eb019']);
            }

            $package = $this->packageService->findActiveByCode($request->requestedPlanType);
            $billingMonths = $this->monthsUntilLicenseExpiry($tenant);

            return [
                $request->requestedPlanType,
                null,
                round((float) $package->price * $billingMonths, 2),
            ];
        }

        $currentPlanType = $this->tenantLicenseService->getTenantLicense($tenant->id)->plan_type;

        if ($currentPlanType === 'trial') {
            throw new InvalidTenantRequest(MessageCodes::$messages['eb021']);
        }

        $extensionDiscounts = config('pricing.extension_discounts', []);

        if ($request->extensionMonths == null || ! isset($extensionDiscounts[$request->extensionMonths])) {
            throw new InvalidTenantRequest(MessageCodes::$messages['eb020']);
        }

        $package = $this->packageService->findActiveByCode($currentPlanType);
        $baseCost = (float) $package->price * $request->extensionMonths;
        $discountRate = (float) $extensionDiscounts[$request->extensionMonths];
        $totalCost = round($baseCost * (1 - $discountRate), 2);

        return [
            $currentPlanType,
            $request->extensionMonths,
            $totalCost,
        ];
    }

    protected function monthsUntilLicenseExpiry(Tenant $tenant): int
    {
        $license = $this->tenantLicenseService->getTenantLicense($tenant->id);

        if ($license->expires_at === null || $license->expires_at->lte(now())) {
            return 1;
        }

        $daysUntilExpiry = max(1, now()->startOfDay()->diffInDays($license->expires_at->copy()->startOfDay()));

        return max(1, (int) ceil($daysUntilExpiry / 30));
    }
}
