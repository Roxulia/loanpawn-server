<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\ResponseObjects\LoanContractSlipDetail;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantNotFound;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\CollateralItemService;
use App\Services\PawnModule\InterestFlowService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Services\TenantModule\TenantAccountingService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantCustomerService;
use App\Services\TenantModule\TenantIdempotencyService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class ManagementService extends BaseTenantService
{
    public function __construct(
        private LoanContractSlipRepository $repository,
        private CollateralItemService $collateralItemService,
        private InterestFlowService $interestFlowService,
        private TenantCustomerService $tenantCustomerService,
        private TenantAccountingService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private TenantLicenseService $tenantLicenseService,
        private CustomerTrustScoreService $customerTrustScoreService
    ) {
    }

    public function create(LoanContractSlipCreate $request): LoanContractSlipDetail
    {
        $this->permissionService->authorizeLoanContractCreate();
        $this->validateCreateRequest($request);
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->tenantLicenseService->checkIfLimitReach('current_month_slip_count', $tenantId)) {
            throw new TenantAccessDenied("Limit Reached");
        }
        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'loan_contract_slip.create',
            $request->idempotencyKey,
            $this->loanContractSlipCreateIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();
        $createdDate = CarbonImmutable::now()->startOfDay();
        $expiryQuotaType = $this->normalizeExpiryQuotaType($request->expiryQuotaType);
        $expireDate = $this->interestFlowService->calculateExpireDate($createdDate, $request->expiryQuota, $expiryQuotaType);

        try {
            $slip = DB::transaction(function () use ($request, $tenantId, $createdBy, $createdDate, $expireDate, $expiryQuotaType) {
                $customerId = $this->tenantCustomerService->createCustomer($request->customer)->customer->id;
                $slipNo = $this->tableIdGenerationService->generate('pawn_loan_contract_slips', $createdDate);

                $slip = $this->repository->create([
                    'tenant_id' => $tenantId,
                    'slip_no' => $slipNo,
                    'customer_id' => $customerId,
                    'loan_amount' => $request->loanAmount,
                    'interest_rate' => $request->interestRate,
                    'interest_type_id' => $request->interestTypeId,
                    'created_date' => $createdDate->toDateString(),
                    'expire_date' => $expireDate->toDateString(),
                    'last_interest_added_date' => $createdDate->toDateString(),
                    'status' => 'active',
                    'notes' => $request->notes,
                    'created_by' => $createdBy,
                    'expiry_quota' => $request->expiryQuota,
                    'expiry_quota_type' => $expiryQuotaType,
                ]);

                $this->collateralItemService->createForSlip($slip, $request->collateralItems);
                $this->interestFlowService->createInitialSchedule($slip, $createdBy);
                $this->tenantLicenseService->incrementCurrentMonthSlipCount($tenantId);
                $this->tenantAccountingService->createOutgoingForReference(
                    $slip,
                    'Loan Contract Transaction',
                    $request->loanAmount,
                    $createdBy
                );

                $this->tenantAuditLogService->log(
                    'loan_contract_slip.created',
                    PawnLoanContractSlip::class,
                    $slip->id,
                    [
                        'slip_no' => $slip->slip_no,
                        'customer_id' => $customerId,
                        'loan_amount' => $request->loanAmount,
                        'collateral_item_count' => count($request->collateralItems),
                    ]
                );

                $this->customerTrustScoreService->recalculateForCustomer($customerId);

                return $this->repository->reload($slip);
            });

            $this->flushLoanContractSlipListCache();
            $detail = LoanContractSlipDetail::fromModel($slip);

            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    201,
                    [
                        'message' => 'Loan contract slip created successfully.',
                        'data' => $detail->toArray(),
                    ],
                    PawnLoanContractSlip::class,
                    $slip->id
                );
            }

            return $detail;
        } catch (Throwable $exception) {
            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markFailed($idempotencyRecord);
            }

            throw $exception;
        }
    }

    public function delete(PawnLoanContractSlip|int $slip): void
    {
        $this->permissionService->authorizeLoanContractDelete();
        $targetSlip = is_int($slip)
            ? $this->findSlipForCurrentTenant($slip)
            : $slip;

        DB::transaction(function () use ($targetSlip) {
            $targetSlip = $this->repository->findByIdWithLock($targetSlip->id);

            if ($targetSlip === null) {
                throw new TenantNotFound('Loan contract slip not found.');
            }

            $this->tenantAuditLogService->log(
                'loan_contract_slip.deleted',
                PawnLoanContractSlip::class,
                $targetSlip->id,
                [
                    'slip_no' => $targetSlip->slip_no,
                    'loan_amount' => $targetSlip->loan_amount,
                ]
            );

            $this->tenantAccountingService->deleteForReference($targetSlip);
            $this->repository->markSlipItemsDeleted($targetSlip);
            $this->repository->delete($targetSlip);
            $this->customerTrustScoreService->recalculateForCustomer((int) $targetSlip->customer_id);
            $this->tenantLicenseService->decrementCurrentMonthSlipCount($this->resolveCurrentTenantUserId());
        });

        $this->flushLoanContractSlipListCache();
    }

    public function deleteBySlipNo(string $slipNo): void
    {
        $this->permissionService->authorizeLoanContractDelete();
        $targetSlip = $this->repository->findBySlipNo($slipNo);

        if ($targetSlip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        $this->delete($targetSlip);
    }

    public function changeStatus(PawnLoanContractSlip $slip, string $status): PawnLoanContractSlip
    {
        $updatedSlip = DB::transaction(function () use ($slip, $status): PawnLoanContractSlip {
            $updatedSlip = $this->repository->updateWithLock($slip, ['status' => $status]);
            $this->customerTrustScoreService->recalculateForCustomer((int) $updatedSlip->customer_id);

            return $updatedSlip;
        });
        $this->flushLoanContractSlipListCache();

        return $updatedSlip;
    }

    protected function validateCreateRequest(LoanContractSlipCreate $request): void
    {
        $customerEmail = $this->normalizeNullableString($request->customer->email);
        $customerPhone = $this->normalizeNullableString($request->customer->phone);

        $request->customer->email = $customerEmail;
        $request->customer->phone = $customerPhone;

        if ($customerEmail === null && $customerPhone === null) {
            throw new InvalidTenantRequest('Customer email or phone is required.');
        }

        if ($request->collateralItems === []) {
            throw new InvalidTenantRequest('At least one collateral item is required.');
        }

        if ($request->loanAmount <= 0) {
            throw new InvalidTenantRequest('Loan amount must be greater than zero.');
        }

        if ($request->interestRate <= 0) {
            throw new InvalidTenantRequest('Interest rate must be greater than zero.');
        }

        if ($request->expiryQuota <= 0) {
            throw new InvalidTenantRequest('Expiry quota must be greater than zero.');
        }

        $this->validateCollateralItems($request->collateralItems);
        $this->normalizeExpiryQuotaType($request->expiryQuotaType);
    }

    protected function loanContractSlipCreateIdempotencyPayload(LoanContractSlipCreate $request): array
    {
        return [
            'customer' => $request->customer,
            'collateral_items' => $request->collateralItems,
            'loan_amount' => $request->loanAmount,
            'interest_rate' => $request->interestRate,
            'interest_type_id' => $request->interestTypeId,
            'notes' => $request->notes,
            'expiry_quota' => $request->expiryQuota,
            'expiry_quota_type' => $this->normalizeExpiryQuotaType($request->expiryQuotaType),
            'created_by' => $request->createdBy,
        ];
    }

    protected function normalizeExpiryQuotaType(string $quotaType): string
    {
        $normalized = ucfirst(strtolower(trim($quotaType)));

        if (! in_array($normalized, ['Day', 'Week', 'Month', 'Year'], true)) {
            throw new InvalidTenantRequest('Expiry quota type must be Day, Week, Month, or Year.');
        }

        return $normalized;
    }

    /**
     * @param array<int, PawnCollateralItemCreate> $collateralItems
     */
    protected function validateCollateralItems(array $collateralItems): void
    {
        foreach ($collateralItems as $collateralItem) {
            if (! $collateralItem instanceof PawnCollateralItemCreate) {
                throw new InvalidTenantRequest('Collateral items must be PawnCollateralItemCreate.');
            }
        }
    }

    protected function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function findSlipForCurrentTenant(int $slipId): PawnLoanContractSlip
    {
        $slip = $this->repository->findById($slipId);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return $slip;
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function flushLoanContractSlipListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('loan-contract-slip-list');
    }
}
