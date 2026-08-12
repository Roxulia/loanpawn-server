<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\RequestObjects\TenantDebtUpdate;
use App\DataObjects\ResponseObjects\TenantDebtDetail;
use App\DataObjects\ResponseObjects\TenantDebtListPage;
use App\Enums\AccountingCategory;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantDebt;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\TenantDebtRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\LoanContractServices\LookUpService;
use App\Services\TableIdGenerationService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantDebtService extends BaseTenantService
{
    protected const TENANT_DEBT_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private TenantDebtRepository $repository,
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private CustomerTrustScoreService $customerTrustScoreService,
        private LookUpService $slipLookUpService,
        private TenantCustomerService $tenantCustomerService
    ) {}

    public function list(int $perPage = 15): TenantDebtListPage
    {
        $this->permissionService->authorizeDebtList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-debt-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-debt-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_DEBT_LIST_CACHE_TTL_SECONDS),
            fn () => TenantDebtListPage::fromPaginator($this->repository->paginate($perPage))
        );
    }

    public function createExternalDebt(TenantDebtCreate $request): TenantDebtDetail
    {
        $this->permissionService->authorizeDebtCreate();
        $request->tenantId = $this->resolveCurrentTenantId();
        $request->createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();

        if ($request->slipCode !== null && $request->customerCode !== null) {
            throw new InvalidTenantRequest('Debt can be linked to either a slip or a customer, not both.');
        }

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'tenant_debt.create',
            $request->idempotencyKey,
            $this->tenantDebtCreateIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $slip = null;
        $customerId = null;

        if ($request->slipCode !== null) {
            $slip = $this->slipLookUpService->findModelBySlipNo($request->slipCode);
            if ($slip->status === 'redeemed') {
                throw new InvalidTenantRequest('Cannot add debt to an already redeemed slip.');
            }

            $targetDate = CarbonImmutable::now()->startOfDay();

            if ((string) $slip->status === 'expired' || CarbonImmutable::parse($slip->expire_at)->lt($targetDate)) {
                throw new InvalidTenantRequest('Cannot add debt to an expired slip.');
            }

            $customerId = $slip->customer_id;
        }

        if ($request->customerCode !== null) {
            $customerId = $this->tenantCustomerService->resolveIdByCode($request->customerCode);
        }

        try {
            $debt = $this->createDebtRecord($request, $slip, $customerId, 'external');

            if ($idempotencyRecord !== null) {
                $detail = TenantDebtDetail::fromModel($debt);
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    201,
                    [
                        'message' => 'Debt created successfully.',
                        'data' => $detail->toArray(),
                    ],
                    TenantDebt::class,
                    $debt->id
                );

                return $detail;
            }

            return TenantDebtDetail::fromModel($debt);
        } catch (Throwable $exception) {
            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markFailed($idempotencyRecord);
            }

            throw $exception;
        }
    }

    public function createInternalDebt(TenantDebtCreate $request): TenantDebtDetail
    {
        $request->tenantId = $this->resolveCurrentTenantId();
        $request->createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();

        if ($request->slipId === null) {
            throw new InvalidTenantRequest('Slip ID is required for internal debt creation.');
        }

        $slip = $this->slipLookUpService->findModelById($request->slipId);
        $debt = $this->createDebtRecord($request, $slip, $slip->customer_id, 'internal');

        return TenantDebtDetail::fromModel($debt);
    }

    protected function createDebtRecord(
        TenantDebtCreate $request,
        ?PawnLoanContractSlip $slip,
        ?int $customerId,
        string $operationType
    ): TenantDebt {
        $debt = DB::transaction(function () use ($request, $slip, $customerId, $operationType) {
            $debt = $this->repository->create([
                'tenant_id' => $request->tenantId,
                'code' => $this->tableIdGenerationService->generate('tenant_debts', CarbonImmutable::now()),
                'slip_id' => $slip === null ? null : $slip->getKey(),
                'customer_id' => $customerId,
                'amount' => $request->amount,
                'description' => $request->description,
                'tag' => $request->tag,
                'is_paid' => $request->isPaid,
                'accepted_by' => $request->acceptedBy,
                'created_by' => $request->createdBy,
            ]);

            if ($operationType === 'internal') {
                $this->tenantAccountingService->createInternalTransfer(
                    $debt,
                    $debt->description,
                    (float) $debt->amount,
                    $debt->created_by
                );
            } else {
                $this->tenantAccountingService->createOutgoingForReference(
                    $debt,
                    $debt->description,
                    (float) $debt->amount,
                    AccountingCategory::Asset,
                    $debt->created_by
                );
            }

            $this->tenantAuditLogService->log(
                'tenant_debt.created',
                TenantDebt::class,
                $debt->id,
                [
                    'debt' => $debt->only([
                        'slip_id',
                        'customer_id',
                        'amount',
                        'description',
                        'tag',
                        'is_paid',
                        'accepted_by',
                    ]),
                ]
            );

            return $debt;
        });

        $this->recalculateTrustScoreForDebt($debt);

        $this->flushTenantDebtListCache();

        return $debt;
    }

    protected function tenantDebtCreateIdempotencyPayload(TenantDebtCreate $request): array
    {
        return [
            'amount' => $request->amount,
            'description' => $request->description,
            'slip_id' => $request->slipId,
            'slip_code' => $request->slipCode,
            'customer_code' => $request->customerCode,
            'tag' => $request->tag,
            'is_paid' => $request->isPaid,
            'accepted_by' => $request->acceptedBy,
            'created_by' => $request->createdBy,
        ];
    }

    public function update(TenantDebtUpdate $request): TenantDebtDetail
    {
        $this->permissionService->authorizeDebtUpdate();
        $debt = $this->findDebtForCurrentTenant($request->debtId);
        $data = [];
        if ($debt->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This item is already Updated.Please refresh');
        }

        if ($request->amount !== null) {
            $data['amount'] = $request->amount;
        }

        if ($request->description !== null) {
            $data['description'] = $request->description;
        }

        if ($request->slipId !== null) {
            $data['slip_id'] = $request->slipId;
        }

        if ($request->tag !== null) {
            $data['tag'] = $request->tag;
        }

        if ($request->isPaid !== null) {
            $data['is_paid'] = $request->isPaid;
        }

        if ($request->acceptedBy !== null) {
            $data['accepted_by'] = $request->acceptedBy;
        }

        if ($data === []) {
            return TenantDebtDetail::fromModel($debt);
        }
        $data['update_key'] = $debt->updateKey + 1;
        $original = $debt->only(array_keys($data));

        $originalCustomerId = $debt->customer_id ?? $debt->slip?->customer_id;

        $updatedDebt = DB::transaction(function () use ($debt, $data, $original) {
            $updatedDebt = $this->repository->updateWithLock($debt, $data);

            $this->tenantAccountingService->syncOutgoingForReference(
                $updatedDebt,
                $updatedDebt->description,
                (float) $updatedDebt->amount,
                AccountingCategory::Asset,
            );

            $this->tenantAuditLogService->log(
                'tenant_debt.updated',
                TenantDebt::class,
                $updatedDebt->id,
                [
                    'before' => $original,
                    'after' => $updatedDebt->only(array_keys($data)),
                ]
            );

            return $updatedDebt;
        });

        $this->recalculateTrustScoreForCustomerIds([
            $originalCustomerId,
            $updatedDebt->slip?->customer_id,
            $updatedDebt->customer_id,
        ]);

        $this->flushTenantDebtListCache();

        return TenantDebtDetail::fromModel($updatedDebt);
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findDebtForCurrentTenant((int) $code)->id;
        }

        return $this->findDebtForCurrentTenantByCode($code)->id;
    }

    public function delete(int $debtId): void
    {
        $this->permissionService->authorizeDebtDelete();
        $debt = $this->findDebtForCurrentTenant($debtId);

        DB::transaction(function () use ($debt) {
            $debt = $this->repository->findByIdWithLock($debt->id);

            if ($debt === null) {
                throw new TenantNotFound('Tenant debt not found.');
            }

            $this->tenantAuditLogService->log(
                'tenant_debt.deleted',
                TenantDebt::class,
                $debt->id,
                [
                    'debt' => $debt->only([
                        'slip_id',
                        'customer_id',
                        'amount',
                        'description',
                        'tag',
                        'is_paid',
                        'accepted_by',
                    ]),
                ]
            );

            $this->tenantAccountingService->deleteForReference($debt);
            $this->repository->delete($debt);
            $this->recalculateTrustScoreForDebt($debt);
        });

        $this->flushTenantDebtListCache();
    }

    public function getTotalUnpaidDebtsForSlip(int $slipId): float
    {
        return $this->repository->totalUnpaidForSlip($slipId);
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function getDebtsForSlip(int $slipId): Collection
    {
        return $this->repository->findBySlipId($slipId);
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function getDebtsForSlipWithLock(int $slipId): Collection
    {
        return $this->repository->findBySlipIdWithLock($slipId);
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function getUnpaidDebtsForSlip(int $slipId): Collection
    {
        return $this->repository->findUnpaidBySlipId($slipId);
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function getUnpaidDebtsForSlipWithLock(int $slipId): Collection
    {
        return $this->repository->findUnpaidBySlipIdWithLock($slipId);
    }

    public function markAsPaid(int $debtId, float $amountPaid): array
    {
        $this->permissionService->authorizeDebtUpdate();
        $updatedDebt = DB::transaction(function () use ($debtId, $amountPaid) {
            $debt = $this->repository->findByIdWithLock($debtId);

            if ($debt === null) {
                throw new TenantNotFound('Tenant debt not found.');
            }

            if ($debt->is_paid) {
                throw new \App\Exceptions\InvalidTenantRequest('Debt is already Paid.');
            }

            if ($amountPaid < $debt->amount) {
                throw new \App\Exceptions\InvalidTenantRequest('Amount paid is less than the debt amount.');
            }

            $updatedDebt = $this->repository->update($debt, [
                'is_paid' => true,
                'accepted_by' => $this->resolveCurrentTenantUserId(),
            ]);

            $this->tenantAccountingService->recordDebtPaymentForReference(
                $updatedDebt,
                "Payment for debt: {$updatedDebt->description}",
                (float) $amountPaid,
                AccountingCategory::Asset,
                $this->resolveCurrentTenantUserId()
            );

            $changeAmount = $amountPaid - (float) $updatedDebt->amount;

            if ($changeAmount > 0.0) {
                $this->tenantAccountingService->createOutgoingForReference(
                    $updatedDebt,
                    "Debt Payment Change: {$updatedDebt->description}",
                    $changeAmount,
                    AccountingCategory::Asset,
                    $this->resolveCurrentTenantUserId()
                );
            }

            $this->tenantAuditLogService->log(
                'tenant_debt.marked_as_paid',
                TenantDebt::class,
                $updatedDebt->id,
                [
                    'before' => [
                        'is_paid' => (bool) $debt->is_paid,
                        'accepted_by' => $debt->accepted_by,
                    ],
                    'after' => [
                        'is_paid' => (bool) $updatedDebt->is_paid,
                        'accepted_by' => $updatedDebt->accepted_by,
                    ],
                ]
            );
            $this->recalculateTrustScoreForDebt($updatedDebt);

            return array_merge($updatedDebt->toArray(), [
                'change_amount' => $changeAmount,
            ]);
        });

        $this->flushTenantDebtListCache();

        return $updatedDebt;
    }

    public function markAsPaidWithoutAccounting(TenantDebt $debt, ?int $acceptedBy = null): TenantDebt
    {
        $updatedDebt = DB::transaction(function () use ($debt, $acceptedBy): TenantDebt {
            $debt = $this->repository->findByIdWithLock($debt->id);

            if ($debt === null) {
                throw new TenantNotFound('Tenant debt not found.');
            }

            $before = [
                'is_paid' => (bool) $debt->is_paid,
                'accepted_by' => $debt->accepted_by,
            ];

            $updatedDebt = $this->repository->update($debt, [
                'is_paid' => true,
                'accepted_by' => $acceptedBy,
            ]);

            $this->tenantAuditLogService->log(
                'tenant_debt.updated',
                TenantDebt::class,
                $updatedDebt->id,
                [
                    'before' => $before,
                    'after' => [
                        'is_paid' => (bool) $updatedDebt->is_paid,
                        'accepted_by' => $updatedDebt->accepted_by,
                    ],
                ],
            );

            $this->recalculateTrustScoreForDebt($updatedDebt);

            return $updatedDebt;
        });

        $this->flushTenantDebtListCache();

        return $updatedDebt;
    }

    protected function findDebtForCurrentTenant(int $debtId): TenantDebt
    {
        $debt = $this->repository->findById($debtId);

        if ($debt === null) {
            throw new TenantNotFound('Tenant debt not found.');
        }

        return $debt;
    }

    protected function findDebtForCurrentTenantByCode(string $code): TenantDebt
    {
        $debt = $this->repository->findByCode($code);

        if ($debt === null) {
            throw new TenantNotFound('Tenant debt not found.');
        }

        return $debt;
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function recalculateTrustScoreForDebt(TenantDebt $debt): void
    {
        $customerId = $debt->customer_id ?? $debt->slip?->customer_id;

        if ($customerId === null) {
            return;
        }

        $this->customerTrustScoreService->recalculateForCustomer((int) $customerId);
    }

    protected function recalculateTrustScoreForCustomerIds(array $customerIds): void
    {
        $this->customerTrustScoreService->recalculateForCustomers(
            array_map('intval', array_filter($customerIds))
        );
    }

    protected function flushTenantDebtListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-debt-list');
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
