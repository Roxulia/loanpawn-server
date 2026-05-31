<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\PawnRedemptionCreate;
use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryItem;
use App\DataObjects\ResponseObjects\PawnRedemptionDetail;
use App\DataObjects\ResponseObjects\PawnRedemptionListPage;
use App\DataObjects\ResponseObjects\PawnRedemptionResult;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantDebt;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Repository\PawnRedemptionRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\LoanContractServices\LookUpService as LoanContractLookUpService;
use App\Services\PawnModule\LoanContractServices\ManagementService as LoanContractManagementService;
use App\Services\TenantModule\TenantAccountingService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantIdempotencyService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Exceptions\AlreadyUpdatedException;
use Throwable;

class PawnRedemptionService extends BaseTenantService
{
    protected const PAWN_REDEMPTION_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private PawnRedemptionRepository $repository,
        private LoanContractLookUpService $loanContractLookUpService,
        private LoanContractManagementService $loanContractManagementService,
        private CollateralItemService $collateralItemService,
        private InterestFlowService $interestFlowService,
        private TenantDebtService $tenantDebtService,
        private TenantAccountingService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TenantIdempotencyService $tenantIdempotencyService,
    ) {
    }

    public function list(int $perPage = 15): PawnRedemptionListPage
    {
        $this->permissionService->authorizeLoanContractList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('pawn-redemption-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('pawn-redemption-list', $version, $page, $perPage),
            now()->addSeconds(self::PAWN_REDEMPTION_LIST_CACHE_TTL_SECONDS),
            fn () => PawnRedemptionListPage::fromPaginator($this->repository->paginate($perPage))
        );
    }

    public function createRedemption(PawnRedemptionCreate $request): PawnRedemptionDetail
    {
        $this->permissionService->authorizeLoanContractCreate();
        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'pawn_redemption.create',
            $request->idempotencyKey,
            $this->pawnRedemptionIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();
        $redemptionDate = $request->redemptionDate === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($request->redemptionDate);

        try {
            $redemption = DB::transaction(function () use ($request, $createdBy, $redemptionDate): PawnRedemption {
                $slip = $this->loanContractLookUpService->findModelBySlipNoWithLock($request->slipNo);
                $this->validateRedeemableSlip($slip);
                $redemptionResult = $this->buildRedemptionResult($slip, $redemptionDate, lockRows: true);

                if (abs($request->calculatedTotal - $redemptionResult->totalAmountToPay) > 0.0001) {
                    throw new InvalidTenantRequest('Calculated total does not match current payable total.');
                }

                if ($request->paymentAmount < $redemptionResult->totalAmountToPay) {
                    throw new InvalidTenantRequest('Payment amount is lower than total redemption amount.');
                }

                $grossAmount = (float) $slip->loan_amount + $redemptionResult->calculatedInterest;
                $netAmount = $redemptionResult->totalAmountToPay;
                $changeAmount = max($request->paymentAmount - $netAmount, 0.0);

                $redemption = $this->repository->create([
                    'tenant_id' => $this->resolveCurrentTenantId(),
                    'slip_number' => $slip->slip_no,
                    'slip_id' => $slip->id,
                    'gross_amount' => $grossAmount,
                    'net_amount' => $netAmount,
                    'interest_amount' => $redemptionResult->calculatedInterest,
                    'received_amount' => $request->paymentAmount,
                    'change_amount' => $changeAmount,
                    'redemption_date' => $redemptionDate->toDateString(),
                    'notes' => $request->notes,
                    'created_by' => $createdBy,
                ]);

                $this->interestFlowService->settleForRedemption($slip, $redemptionDate, $createdBy,$request->interests);
                $this->settleDebtForRedemption($slip,$request->debts, $createdBy);
                $this->loanContractManagementService->changeStatus($slip, 'redeemed');
                $this->collateralItemService->redeemProcess($slip);

                $this->tenantAuditLogService->log(
                    'pawn_redemption.created',
                    PawnRedemption::class,
                    $redemption->id,
                    [
                        'slip_id' => $slip->id,
                        'gross_amount' => (float) $redemption->gross_amount,
                        'net_amount' => (float) $redemption->net_amount,
                        'received_amount' => (float) $redemption->received_amount,
                        'change_amount' => (float) $redemption->change_amount,
                    ],
                    $createdBy
                );

                $this->tenantAccountingService->create(new TenantAccountingCreate(
                    description: 'Redemption Transaction',
                    transactionType: 'incoming',
                    amount: (float) $redemption->net_amount,
                    createdBy: $createdBy,
                    referenceId: $redemption->id,
                    referenceType: PawnRedemption::class
                ));

                return $redemption;
            });

            $this->flushPawnRedemptionListCache();
            $detail = PawnRedemptionDetail::fromModel($redemption);

            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    201,
                    [
                        'message' => 'Pawn redemption created successfully.',
                        'data' => $detail->toArray(),
                    ],
                    PawnRedemption::class,
                    $redemption->id
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

    public function getRedemptionResult(int $slipId): PawnRedemptionResult
    {
        $this->permissionService->authorizeLoanContractList();
        $slip = $this->loanContractLookUpService->findModelById($slipId);

        return $this->buildRedemptionResult($slip);
    }

    public function getRedemptionResultBySlipNo(string $slipNo): PawnRedemptionResult
    {
        $this->permissionService->authorizeLoanContractList();
        $slip = $this->loanContractLookUpService->findModelBySlipNo($slipNo);

        return $this->buildRedemptionResult($slip);
    }

    public function findById(int $redemptionId): PawnRedemptionDetail
    {
        $this->permissionService->authorizeLoanContractList();
        $redemption = $this->repository->findById($redemptionId);

        if ($redemption === null) {
            throw new TenantNotFound('Pawn redemption not found.');
        }

        return PawnRedemptionDetail::fromModel($redemption);
    }

    public function findBySlipNumber(string $slipNumber): PawnRedemptionDetail
    {
        $this->permissionService->authorizeLoanContractList();
        $redemption = $this->repository->findBySlipNumber($slipNumber);

        if ($redemption === null) {
            throw new TenantNotFound('Pawn redemption not found.');
        }

        return PawnRedemptionDetail::fromModel($redemption);
    }

    public function getBySlipId(int $slipId): array
    {
        $this->permissionService->authorizeLoanContractList();

        return $this->repository->getBySlipId($slipId)
            ->map(fn (PawnRedemption $redemption): PawnRedemptionDetail => PawnRedemptionDetail::fromModel($redemption))
            ->all();
    }

    protected function buildRedemptionResult(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null, bool $lockRows = false): PawnRedemptionResult
    {
        $this->validateRedeemableSlip($slip, $date);
        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();
        $interestPayments = $lockRows
            ? $this->interestFlowService->getInterestBreakdownUntilNowWithLock($slip, $targetDate)
            : $this->interestFlowService->getInterestBreakdownUntilNow($slip, $targetDate);
        $calculatedInterest = array_reduce(
            $interestPayments,
            fn (float $total, InterestPaymentHistoryItem $payment): float => $total + ($payment->isPaid ? 0.0 : $payment->interestAmount),
            0.0
        );
        $debts = $lockRows
            ? $this->tenantDebtService->getDebtsForSlipWithLock($slip->id)
            : $this->tenantDebtService->getDebtsForSlip($slip->id);
        $totalDebt = $debts
            ->filter(fn (TenantDebt $debt): bool => ! $debt->is_paid)
            ->sum(fn (TenantDebt $debt): float => (float) $debt->amount);

        return PawnRedemptionResult::fromValues(
            $slip,
            $calculatedInterest,
            (float) $totalDebt,
            $interestPayments,
            $debts->all(),
            $this->collateralItemService->getItemsBySlip($slip)
        );
    }

    protected function validateRedeemableSlip(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null): void
    {
        if ($slip->status === 'redeemed') {
            throw new InvalidTenantRequest('Cannot redeem an already redeemed slip.');
        }

        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();

        if ((string) $slip->status === 'expired' || CarbonImmutable::parse($slip->expire_date)->startOfDay()->lt($targetDate->startOfDay())) {
            throw new InvalidTenantRequest('Cannot redeem an expired slip.');
        }
    }

    protected function settleDebtForRedemption(PawnLoanContractSlip $slip,array $debts, ?int $createdBy): void
    {
        $existingDebts = $this->tenantDebtService->getUnpaidDebtsForSlipWithLock($slip->id);
        $requestedBreakdownById = collect($debts)->keyBy('id');

        if ($existingDebts->count() !== $requestedBreakdownById->count()) {
            throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
        }
        foreach ($existingDebts as $debt) {
            $requestedBreakdown = $requestedBreakdownById->get($debt->id);

            if ($requestedBreakdown === null || (int) $debt->update_key !== $requestedBreakdown->updateKey) {
                throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
            }
        }
        foreach ($existingDebts as $debt)
        {
            $this->tenantDebtService->markAsPaidWithoutAccounting($debt, $createdBy);
        }

    }

    protected function pawnRedemptionIdempotencyPayload(PawnRedemptionCreate $request): array
    {
        return [
            'slip_no' => $request->slipNo,
            'calculated_total' => $request->calculatedTotal,
            'payment_amount' => $request->paymentAmount,
            'debts' => $request->debts,
            'interests' => $request->interests,
            'redemption_date' => $request->redemptionDate,
            'notes' => $request->notes,
            'created_by' => $request->createdBy,
        ];
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function flushPawnRedemptionListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('pawn-redemption-list');
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
