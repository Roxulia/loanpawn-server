<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\PawnRedemptionCreate;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryItem;
use App\DataObjects\ResponseObjects\PawnRedemptionDetail;
use App\DataObjects\ResponseObjects\PawnRedemptionListPage;
use App\DataObjects\ResponseObjects\PawnRedemptionResult;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantDebt;
use App\Models\FinancialAccount;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Repository\PawnRedemptionRepository;
use App\Services\BaseTenantService;
use App\Services\PawnModule\LoanContractServices\LookUpService as LoanContractLookUpService;
use App\Services\PawnModule\LoanContractServices\ManagementService as LoanContractManagementService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantIdempotencyService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TenantIdempotencyService $tenantIdempotencyService,
        private MultiAccountManagement $multiAccountManagement,
        private FinancialAccountTransactionService $financialAccountTransactionService,
    ) {}

    public function list(int $perPage = 15, ?CarbonImmutable $startDate = null, ?CarbonImmutable $endDate = null): PawnRedemptionListPage
    {
        $this->permissionService->authorizeLoanContractList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('pawn-redemption-list');
        $cacheKey = $this->tenantScopedCacheKeys->paginatedListKey('pawn-redemption-list', $version, $page, $perPage)
            .':start-date:'.($startDate?->toDateString() ?? 'any')
            .':end-date:'.($endDate?->toDateString() ?? 'any');

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PAWN_REDEMPTION_LIST_CACHE_TTL_SECONDS),
            fn () => PawnRedemptionListPage::fromPaginator($this->repository->paginate($perPage, $startDate, $endDate))
        );
    }

    public function createRedemption(PawnRedemptionCreate $request): PawnRedemptionDetail
    {
        $this->permissionService->authorizeLoanContractCreate();
        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->accountId);
        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'pawn_redemption.create',
            $request->idempotencyKey,
            $this->pawnRedemptionIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();
        $redemptionDate = $request->redemptionAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($request->redemptionAt);

        try {
            $redemption = DB::transaction(function () use ($request, $createdBy, $redemptionDate, $financialAccount): PawnRedemption {
                $slip = $this->loanContractLookUpService->findModelBySlipNoWithLock($request->slipNo);
                $this->validateRedeemableSlip($slip);
                $this->assertRedemptionAccountCurrency($slip, $financialAccount);
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
                    'account_id' => $financialAccount->id,
                    'gross_amount' => $grossAmount,
                    'net_amount' => $netAmount,
                    'interest_amount' => $redemptionResult->calculatedInterest,
                    'received_amount' => $request->paymentAmount,
                    'change_amount' => $changeAmount,
                    'redemption_at' => $redemptionDate,
                    'notes' => $request->notes,
                    'created_by' => $createdBy,
                ]);

                $this->interestFlowService->settleForRedemption($slip, $redemptionDate, $financialAccount, $createdBy, $request->interests);
                $this->settleDebtForRedemption($slip, $request->debts, $financialAccount, $createdBy);
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

                $accountingTransaction = $this->tenantAccountingService->recordLoanRedemption(
                    $redemption,
                    'Redemption Transaction',
                    (float) $redemption->received_amount,
                    $financialAccount->currency,
                    $createdBy,
                    $request->reportingExchangeRate,
                );
                $this->financialAccountTransactionService->recordPawnRedemption(
                    $financialAccount,
                    (float) $redemption->received_amount,
                    $redemption->slip_number,
                    PawnRedemption::class,
                    'Redemption Transaction',
                    $createdBy,
                    $accountingTransaction->id,
                );

                if ((float) $redemption->change_amount > 0.0) {
                    $accountingTransaction = $this->tenantAccountingService->recordRedemptionChange(
                        $redemption,
                        'Redemption Change Transaction',
                        (float) $redemption->change_amount,
                        $financialAccount->currency,
                        $createdBy,
                        $request->reportingExchangeRate,
                    );
                    $this->financialAccountTransactionService->recordAdjustment(
                        $financialAccount,
                        (float) $redemption->change_amount,
                        'credit',
                        $redemption->slip_number,
                        PawnRedemption::class,
                        'Redemption Change Transaction',
                        $createdBy,
                        $accountingTransaction->id,
                    );
                }

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
        if ($slip->account_id === null) {
            throw new InvalidTenantRequest('Loan creation account is required before redemption.');
        }
        $loanAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount((int) $slip->account_id);
        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();
        $interestPayments = $lockRows
            ? $this->interestFlowService->getInterestBreakdownUntilNowWithLock($slip, $targetDate)
            : $this->interestFlowService->getInterestBreakdownUntilNow($slip, $targetDate);
        $calculatedInterest = array_reduce(
            $interestPayments,
            fn (float $total, InterestPaymentHistoryItem $payment): float => $total + ($payment->isPaid ? 0.0 : $payment->interestAmount),
            0.0
        );
        $debts = $this->tenantDebtService->getUnpaidDebtsForSlipCurrency($slip->id, (int) $loanAccount->currency_id, $lockRows);
        $excludedDebts = $this->tenantDebtService->getUnpaidDebtsForSlipExceptCurrency($slip->id, (int) $loanAccount->currency_id);
        $totalDebt = $debts->sum(fn (TenantDebt $debt): float => (float) $debt->amount);
        $excludedDebtTotal = $excludedDebts->sum(fn (TenantDebt $debt): float => (float) $debt->amount);

        return PawnRedemptionResult::fromValues(
            $slip,
            $calculatedInterest,
            (float) $totalDebt,
            (float) $excludedDebtTotal,
            $interestPayments,
            $debts->all(),
            $excludedDebts->all(),
            $this->collateralItemService->getItemsBySlip($slip)
        );
    }

    protected function validateRedeemableSlip(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null): void
    {
        if ($slip->status === 'redeemed') {
            throw new InvalidTenantRequest('Cannot redeem an already redeemed slip.');
        }

        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();

        if ((string) $slip->status === 'expired' || CarbonImmutable::parse($slip->expire_at)->lt($targetDate)) {
            throw new InvalidTenantRequest('Cannot redeem an expired slip.');
        }
    }

    protected function settleDebtForRedemption(PawnLoanContractSlip $slip, array $debts, FinancialAccount $acceptAccount, ?int $createdBy): void
    {
        $existingDebts = $this->tenantDebtService->getUnpaidDebtsForSlipCurrency($slip->id, (int) $acceptAccount->currency_id, true);
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
        foreach ($existingDebts as $debt) {
            $this->tenantDebtService->markAsPaidWithoutAccounting($debt, $acceptAccount, $createdBy);
        }

    }

    protected function assertRedemptionAccountCurrency(PawnLoanContractSlip $slip, FinancialAccount $acceptAccount): void
    {
        if ($slip->account_id === null) {
            throw new InvalidTenantRequest('Loan creation account is required before redemption.');
        }

        $createdAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount((int) $slip->account_id);
        if ((int) $createdAccount->currency_id !== (int) $acceptAccount->currency_id) {
            throw new InvalidTenantRequest('Loan creation and redemption accounts must use the same currency.');
        }
    }

    protected function pawnRedemptionIdempotencyPayload(PawnRedemptionCreate $request): array
    {
        return [
            'slip_no' => $request->slipNo,
            'calculated_total' => $request->calculatedTotal,
            'payment_amount' => $request->paymentAmount,
            'account_id' => $request->accountId,
            'debts' => $request->debts,
            'interests' => $request->interests,
            'redemption_at' => $request->redemptionAt,
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
