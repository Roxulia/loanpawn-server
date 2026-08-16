<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\InterestPaymentAccept;
use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\DataObjects\ResponseObjects\InterestCalculationResult;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryItem;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryListPage;
use App\Enums\AccountingCategory;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\FinancialAccount;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Repository\PawnInterestPaymentRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantIdempotencyService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class InterestFlowService extends BaseTenantService
{
    public function __construct(
        private LoanContractSlipRepository $loanContractSlipRepository,
        private PawnInterestPaymentRepository $repository,
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantDebtService $tenantDebtService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private CustomerTrustScoreService $customerTrustScoreService,
        private MultiAccountManagement $multiAccountManagement,
        private FinancialAccountTransactionService $financialAccountTransactionService,
    ) {}

    public function getInterestPaymentHistory(int $perPage = 15): InterestPaymentHistoryListPage
    {
        return InterestPaymentHistoryListPage::fromPaginator(
            $this->repository->history($perPage)
        );
    }

    public function lastAccruedInterestPayment(int $slipId): ?PawnInterestPayment
    {
        return $this->repository->findLastAccruedInterestPayment($slipId);
    }

    public function calculateInterestBySlipNo(string $slipNo): InterestCalculationResult
    {
        $slip = $this->resolveActiveSlipBySlipNo($slipNo);
        if ($slip->account_id === null) {
            throw new InvalidTenantRequest('Loan creation account is required before interest can be calculated.');
        }
        $currentAt = CarbonImmutable::now();
        $currentDate = $currentAt->startOfDay();
        $payments = $this->dueUnpaidPayments($slip, $currentDate);
        $interestBreakdown = $payments
            ->map(fn (PawnInterestPayment $payment): InterestBreakDown => InterestBreakDown::fromModel($payment))
            ->all();

        return InterestCalculationResult::fromValues(
            slipNo: $slip->slip_no,
            slipUpdateKey: $slip->update_key,
            accountId: (int) $slip->account_id,
            currentDate: $currentDate->toDateString(),
            interestBreakdown: $interestBreakdown,
        );
    }

    /**
     * @return array{status: string, debtAmount: float, changeAmount: float, paidAmount: float}
     */
    public function payInterestBySlipNo(string $slipNo, InterestPaymentAccept $request): array
    {
        if ($request->paymentAmount <= 0) {
            throw new InvalidTenantRequest('Payment amount must be greater than zero.');
        }

        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->acceptAccountId);

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'interest_payment.pay',
            $request->idempotencyKey,
            $this->interestPaymentIdempotencyPayload($slipNo, $request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $currentAt = CarbonImmutable::now();
        $currentDate = $currentAt->startOfDay();

        try {
            $result = DB::transaction(function () use ($slipNo, $request, $currentAt, $currentDate, $financialAccount): array {
                $slip = $this->resolveActiveSlipBySlipNoWithLock($slipNo);
                if ($slip->update_key !== $request->slipUpdateKey) {
                    throw new AlreadyUpdatedException('This Slip is already updated by others.Please refresh');
                }
                $payments = $this->repository->findUnpaidInterestUntilDateBySlipIdWithLock($slip->id, $currentDate->toDateString());

                if ($payments->isEmpty()) {
                    throw new InvalidTenantRequest('No unpaid interest payment found for this slip.');
                }
                $this->assertPaymentsUseCurrency($payments, (int) $financialAccount->currency_id);
                $requestedBreakdownById = collect($request->interestBreakdown)->keyBy('id');

                if ($payments->count() !== $requestedBreakdownById->count()) {
                    throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
                }

                foreach ($payments as $payment) {
                    $requestedBreakdown = $requestedBreakdownById->get($payment->id);

                    if ($requestedBreakdown === null || (int) $payment->update_key !== $requestedBreakdown->updateKey) {
                        throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
                    }
                }

                $totalInterestAmount = $payments->sum(fn (PawnInterestPayment $payment): float => (float) $payment->calculated_interest);

                if ($request->paymentAmount < $totalInterestAmount && ! $request->recordDebt) {
                    throw new InvalidTenantRequest('Payment amount is not enough to satisfy total interest amount.');
                }

                $leftAmount = $request->paymentAmount;
                $debtAmount = 0.0;
                $lastPaidPayment = null;
                $paidInterestRowCount = 0;
                $paidPayments = [];

                foreach ($payments as $payment) {
                    if ($leftAmount <= 0.0) {
                        break;
                    }

                    $calculatedInterest = (float) $payment->calculated_interest;
                    $appliedAmount = min($leftAmount, $calculatedInterest);
                    $changeAmount = 0.0;

                    if ($appliedAmount < $calculatedInterest) {
                        if (! $request->recordDebt) {
                            throw new InvalidTenantRequest('Payment amount is not enough to satisfy total interest amount.');
                        }

                        $debtAmount += $calculatedInterest - $appliedAmount;
                    }

                    $updatedPayment = $this->repository->update($payment, [
                        'is_paid' => true,
                        'accept_account_id' => $financialAccount->id,
                        'created_by' => $this->resolveCurrentTenantUserId(),
                        'payment_at' => $currentAt,
                        'payment_amount' => $appliedAmount,
                        'change_amount' => $changeAmount,
                        'update_key' => $payment->update_key + 1,
                    ]);

                    $paidPayments[] = $updatedPayment;
                    $paidInterestRowCount++;

                    if ($appliedAmount < $calculatedInterest) {
                        $this->createRemainingInterestDebt($slip, $updatedPayment);
                        $leftAmount = 0.0;
                        $lastPaidPayment = $updatedPayment;
                        break;
                    }

                    $lastPaidPayment = $updatedPayment;
                    $leftAmount -= $appliedAmount;
                }

                if ($lastPaidPayment === null) {
                    throw new InvalidTenantRequest('No interest payment row was processed.');
                }

                $changeAmount = max($leftAmount, 0.0);

                if ($changeAmount > 0.0) {
                    $lastPaidPayment = $this->repository->update($lastPaidPayment, [
                        'payment_amount' => (float) $lastPaidPayment->payment_amount + $changeAmount,
                        'change_amount' => $changeAmount,
                        'notes' => trim(($lastPaidPayment->notes ? $lastPaidPayment->notes.PHP_EOL : '').'Change amount: '.$changeAmount),
                    ]);

                    $paidPayments[array_key_last($paidPayments)] = $lastPaidPayment;
                }

                foreach ($paidPayments as $paidPayment) {
                    $this->recordInterestPaymentAccounting($paidPayment, $financialAccount, $request->reportingExchangeRate);
                    $this->logInterestPaymentUpdate($paidPayment);
                }

                if ($changeAmount > 0.0) {
                    $this->recordInterestPaymentChangeAccounting($lastPaidPayment, $financialAccount, $request->reportingExchangeRate);
                }

                foreach ($this->repository->findPaymentsAfterPaymentWithLock($slip->id, $lastPaidPayment) as $futurePayment) {
                    $this->repository->delete($futurePayment);
                }

                $updatedSlip = $this->loanContractSlipRepository->update($slip, [
                    'last_interest_paid_at' => $currentAt,
                    'last_interest_added_at' => $currentAt,
                    'expire_at' => $this->calculateRenewedExpireDate($slip, $currentAt, $paidInterestRowCount),
                    'update_key' => $slip->update_key + 1,
                ]);

                $nextScheduleStart = $this->calculateNextScheduleStartDate($currentDate, (string) $slip->expiry_quota_type);

                $this->createLoanContractInterestPayments(
                    $updatedSlip,
                    $nextScheduleStart,
                    CarbonImmutable::parse($updatedSlip->expire_at)->startOfDay(),
                    $this->resolveCurrentTenantUserId()
                );

                $this->customerTrustScoreService->recalculateForCustomer((int) $updatedSlip->customer_id);

                return [
                    'status' => $debtAmount > 0.0 ? 'debt_created' : ($changeAmount > 0.0 ? 'change_made' : 'success'),
                    'debtAmount' => $debtAmount,
                    'changeAmount' => $changeAmount,
                    'paidAmount' => $request->paymentAmount - $changeAmount,
                ];
            });

            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    200,
                    [
                        'message' => 'Interest payment processed successfully.',
                        'data' => $result,
                    ]
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markFailed($idempotencyRecord);
            }

            throw $exception;
        }
    }

    public function acceptInterestUntilNow(PawnLoanContractSlip $slip, bool $isRedemptionProcess = false): void
    {
        if ($slip->account_id === null) {
            throw new InvalidTenantRequest('A financial account is required before interest can be accepted.');
        }

        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount((int) $slip->account_id);

        DB::transaction(function () use ($slip, $isRedemptionProcess, $financialAccount): void {
            $this->loanContractSlipRepository->findByIdWithLock($slip->id);
            $paymentAt = CarbonImmutable::now();
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $paymentAt->toDateString());
            $this->assertPaymentsUseCurrency($payments, (int) $financialAccount->currency_id);

            foreach ($payments as $payment) {
                if ($payment->is_paid) {
                    continue;
                }

                $payment = $this->repository->update($payment, [
                    'is_paid' => true,
                    'accept_account_id' => $financialAccount->id,
                    'created_by' => $this->resolveCurrentTenantUserId(),
                    'payment_at' => $paymentAt,
                    'payment_amount' => (float) $payment->calculated_interest,
                    'change_amount' => 0,
                ]);

                $this->recordInterestPaymentAccounting($payment, $financialAccount);
                $this->logInterestPaymentUpdate($payment);
            }

            if (! $isRedemptionProcess) {
                return;
            }

            $futurePayments = $this->repository->findInterestAfterDateBySlipIdWithLock($slip->id, $paymentAt->toDateString());

            foreach ($futurePayments as $payment) {
                $this->repository->delete($payment);
            }
        });
    }

    /**
     * @return array{slipId: int, interestBreakdown: array<int, array<string, mixed>>, totalInterestAmount: float}
     */
    public function interestPaymentProcess(PawnLoanContractSlip $slip): array
    {
        $this->validateActiveSlip($slip);

        $payments = $this->dueUnpaidPayments($slip, CarbonImmutable::now()->startOfDay());
        $interestBreakdown = $payments->map(fn (PawnInterestPayment $payment): array => [
            'id' => $payment->id,
            'start_period_at' => $payment->start_period_at?->toISOString(),
            'end_period_at' => $payment->end_period_at?->toISOString(),
            'interest_amount' => (float) $payment->calculated_interest,
            'is_paid' => (bool) $payment->is_paid,
        ])->all();

        return [
            'slipId' => $slip->id,
            'interestBreakdown' => $interestBreakdown,
            'totalInterestAmount' => $this->calculateTotalInterestAmount($interestBreakdown),
        ];
    }

    public function getTotalInterestAmount(PawnLoanContractSlip $slip): float
    {
        return $this->calculateTotalInterestAmount($this->interestPaymentProcess($slip)['interestBreakdown']);
    }

    /**
     * @return InterestPaymentHistoryItem[]
     */
    public function getInterestBreakdownUntilNow(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null): array
    {
        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();

        return $this->repository->findInterestUntilDateBySlipId($slip->id, $targetDate->toDateString())
            ->map(fn (PawnInterestPayment $payment): InterestPaymentHistoryItem => InterestPaymentHistoryItem::fromModel($payment))
            ->all();
    }

    /**
     * @return InterestPaymentHistoryItem[]
     */
    public function getInterestBreakdownUntilNowWithLock(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null): array
    {
        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();

        return $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $targetDate->toDateString())
            ->map(fn (PawnInterestPayment $payment): InterestPaymentHistoryItem => InterestPaymentHistoryItem::fromModel($payment))
            ->all();
    }

    public function settleForRedemption(PawnLoanContractSlip $slip, CarbonImmutable $paymentDate, FinancialAccount $acceptAccount, ?int $createdBy = null, array $interests = []): void
    {
        DB::transaction(function () use ($slip, $paymentDate, $acceptAccount, $createdBy, $interests): void {
            $this->loanContractSlipRepository->findByIdWithLock($slip->id);
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $paymentDate->toDateString());
            $requestedBreakdownById = collect($interests)->keyBy('id');
            $this->assertPaymentsUseCurrency($payments, (int) $acceptAccount->currency_id);

            if ($payments->count() !== $requestedBreakdownById->count()) {
                throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
            }
            foreach ($payments as $payment) {
                $requestedBreakdown = $requestedBreakdownById->get($payment->id);

                if ($requestedBreakdown === null || (int) $payment->update_key !== $requestedBreakdown->updateKey) {
                    throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
                }
            }
            foreach ($payments as $payment) {
                if ($payment->is_paid) {
                    continue;
                }

                $this->repository->update($payment, [
                    'is_paid' => true,
                    'accept_account_id' => $acceptAccount->id,
                    'created_by' => $createdBy,
                    'payment_at' => $paymentDate,
                    'payment_amount' => (float) $payment->calculated_interest,
                    'change_amount' => 0,
                    'update_key' => $payment->update_key + 1,
                ]);
            }

            foreach ($this->repository->findInterestAfterDateBySlipIdWithLock($slip->id, $paymentDate->toDateString()) as $futurePayment) {
                $this->repository->delete($futurePayment);
            }

            $this->customerTrustScoreService->recalculateForCustomer((int) $slip->customer_id);
        });
    }

    public function createInitialSchedule(PawnLoanContractSlip $slip, ?int $createdBy = null): void
    {
        if ($slip->interest_type_id === null) {
            throw new InvalidTenantRequest('Interest type is required.');
        }

        $this->createLoanContractInterestPayments(
            $slip,
            CarbonImmutable::parse($slip->created_at)->startOfDay(),
            CarbonImmutable::parse($slip->expire_at)->startOfDay(),
            $createdBy
        );
    }

    public function calculateExpireDate(CarbonInterface $currentDate, int $quota, string $quotaType): CarbonImmutable
    {
        $date = CarbonImmutable::parse($currentDate)->startOfDay();

        return match (ucfirst(strtolower(trim($quotaType)))) {
            'Day' => $date->addDays($quota),
            'Week' => $date->addWeeks($quota),
            'Month' => $date->addMonths($quota),
            'Year' => $date->addYears($quota),
            default => throw new InvalidTenantRequest('Expiry quota type must be Day, Week, Month, or Year.'),
        };
    }

    protected function calculateRenewedExpireDate(
        PawnLoanContractSlip $slip,
        CarbonInterface $currentDate,
        int $paidInterestRowCount
    ): CarbonImmutable {
        $quotaType = ucfirst(strtolower(trim((string) $slip->expiry_quota_type)));
        $slip->loadMissing('interestType');

        if ($this->resolveInterestIntervalUnit($slip) === 'day' && $quotaType === 'Day') {
            return CarbonImmutable::parse($slip->expire_at)
                ->startOfDay()
                ->addDays($paidInterestRowCount);
        }

        return $this->calculateExpireDate(
            $currentDate,
            (int) $slip->expiry_quota,
            (string) $slip->expiry_quota_type
        );
    }

    protected function calculateNextScheduleStartDate(CarbonInterface $currentDate, string $quotaType): CarbonImmutable
    {
        return $this->calculateExpireDate($currentDate, 1, $quotaType);
    }

    public function calculateEndDate(CarbonInterface $currentDate, PawnLoanContractSlip $slip): CarbonImmutable
    {
        $date = CarbonImmutable::parse($currentDate);
        $interestType = $slip->interestType;
        $interestTypeName = $interestType?->name;

        return match ($interestTypeName) {
            'Daily' => $date->addDay(),
            'Weekly' => $date->addWeek(),
            'Monthly' => $date->addMonth(),
            default => $date->addDays((int) ($interestType?->duration_in_days ?? 1)),
        };
    }

    protected function createInterestPayments(PawnLoanContractSlip $slip, CarbonImmutable $currentDate, CarbonImmutable $expireDate): void
    {
        $slip->loadMissing('interestType');

        $tenantId = $this->resolveCurrentTenantId();
        $interestAmount = ((float) $slip->loan_amount * (float) $slip->interest_rate) / 100;

        while ($currentDate->lt($expireDate)) {
            $endDate = $this->calculateEndDate($currentDate, $slip);
            $payment = $this->repository->create([
                'tenant_id' => $tenantId,
                'slip_id' => $slip->id,
                'created_account_id' => $slip->account_id,
                'payment_amount' => 0,
                'change_amount' => 0,
                'calculated_interest' => $interestAmount,
                'created_by' => null,
                'start_period_at' => $currentDate,
                'end_period_at' => $endDate,
                'is_paid' => false,
            ]);

            $this->tenantAuditLogService->log(
                'pawn_interest_payment.created',
                PawnInterestPayment::class,
                $payment->id,
                [
                    'slip_id' => $slip->id,
                    'start_period_at' => $payment->start_period_at?->toISOString(),
                    'end_period_at' => $payment->end_period_at?->toISOString(),
                    'calculated_interest' => (float) $payment->calculated_interest,
                ]
            );

            $currentDate = $endDate;
        }
    }

    protected function createLoanContractInterestPayments(
        PawnLoanContractSlip $slip,
        CarbonImmutable $startDate,
        CarbonImmutable $expireDate,
        ?int $createdBy
    ): void {
        $slip->loadMissing('interestType');

        if ($slip->interestType === null) {
            throw new TenantNotFound('Interest type not found.');
        }

        $tenantId = $this->resolveCurrentTenantId();
        $interestAmount = ((float) $slip->loan_amount * (float) $slip->interest_rate) / 100;
        $currentStart = $startDate;
        $useInclusiveBoundary = in_array($this->resolveInterestIntervalUnit($slip), ['day', 'week'], true);

        while ($useInclusiveBoundary ? $currentStart->lte($expireDate) : $currentStart->lt($expireDate)) {
            $nextStart = $this->resolveNextInterestPeriodStart($currentStart, $slip);
            $endDate = $nextStart->subDay();

            if ($endDate->gt($expireDate)) {
                $endDate = $expireDate;
            }

            $payment = $this->repository->create([
                'tenant_id' => $tenantId,
                'slip_id' => $slip->id,
                'created_account_id' => $slip->account_id,
                'payment_amount' => 0,
                'change_amount' => 0,
                'calculated_interest' => $interestAmount,
                'notes' => null,
                'created_by' => $createdBy,
                'start_period_at' => $currentStart,
                'end_period_at' => $endDate,
                'is_paid' => false,
            ]);

            $this->tenantAuditLogService->log(
                'pawn_interest_payment.created',
                PawnInterestPayment::class,
                $payment->id,
                [
                    'slip_id' => $slip->id,
                    'start_period_at' => $payment->start_period_at?->toISOString(),
                    'end_period_at' => $payment->end_period_at?->toISOString(),
                    'calculated_interest' => (float) $payment->calculated_interest,
                ],
                $createdBy
            );

            if ($endDate->gte($expireDate)) {
                break;
            }

            $currentStart = $endDate->addDay();
        }
    }

    protected function resolveAccrualStartDate(PawnInterestPayment $lastPayment): CarbonImmutable
    {
        $latestDate = collect([
            $lastPayment->end_period_at,
            $lastPayment->payment_at,
            $lastPayment->start_period_at,
        ])
            ->filter()
            ->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date)->startOfDay())
            ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp())
            ->last();

        if ($latestDate === null) {
            throw new InvalidTenantRequest('Last interest payment has no usable accrual dates.');
        }

        return $latestDate;
    }

    protected function createRemainingInterestDebt(PawnLoanContractSlip $slip, PawnInterestPayment $payment): float
    {
        $remainingInterest = (float) $payment->calculated_interest - (float) $payment->payment_amount;

        if ($remainingInterest <= 0.0) {
            return 0.0;
        }

        $this->tenantDebtService->createInternalDebt(new TenantDebtCreate(
            amount: $remainingInterest,
            description: 'Remaining interest from payment ID: '.$payment->id,
            createdAccountId: (int) $payment->created_account_id,
            slipId: $slip->id,
            tag: 'InterestPayment',
            createdBy: $this->resolveCurrentTenantUserId(),
        ), AccountingCategory::Revenue);

        return $remainingInterest;
    }

    protected function recordInterestPaymentAccounting(PawnInterestPayment $payment, FinancialAccount $financialAccount, ?float $reportingExchangeRate = null): void
    {
        if ((float) $payment->payment_amount <= 0.0) {
            return;
        }

        $accountingTransaction = $this->tenantAccountingService->recordInterestPayment(
            $payment,
            'Interest Payment Transaction',
            (float) $payment->payment_amount,
            $financialAccount->currency,
            $payment->created_by,
            $reportingExchangeRate,
        );
        $this->financialAccountTransactionService->recordPawnInterestPayment(
            $financialAccount,
            (float) $payment->payment_amount,
            (string) $payment->id,
            PawnInterestPayment::class,
            'Interest Payment Transaction',
            $payment->created_by,
            $accountingTransaction->id,
        );
    }

    protected function recordInterestPaymentChangeAccounting(PawnInterestPayment $payment, FinancialAccount $financialAccount, ?float $reportingExchangeRate = null): void
    {
        if ((float) $payment->change_amount <= 0.0) {
            return;
        }

        $accountingTransaction = $this->tenantAccountingService->recordInterestPaymentChange(
            $payment,
            'Interest Payment Change Transaction',
            (float) $payment->change_amount,
            $financialAccount->currency,
            $payment->created_by,
            $reportingExchangeRate,
        );
        $this->financialAccountTransactionService->recordAdjustment(
            $financialAccount,
            (float) $payment->change_amount,
            'credit',
            (string) $payment->id,
            PawnInterestPayment::class,
            'Interest Payment Change Transaction',
            $payment->created_by,
            $accountingTransaction->id,
        );
    }

    protected function logInterestPaymentUpdate(PawnInterestPayment $payment): void
    {
        $this->tenantAuditLogService->log(
            'pawn_interest_payment.updated',
            PawnInterestPayment::class,
            $payment->id,
            [
                'slip_id' => $payment->slip_id,
                'payment_amount' => (float) $payment->payment_amount,
                'change_amount' => (float) $payment->change_amount,
                'calculated_interest' => (float) $payment->calculated_interest,
                'is_paid' => (bool) $payment->is_paid,
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $interestBreakdown
     */
    protected function calculateTotalInterestAmount(array $interestBreakdown): float
    {
        return array_reduce(
            $interestBreakdown,
            fn (float $total, array $row): float => $total + ((bool) $row['is_paid'] ? 0.0 : (float) $row['interest_amount']),
            0.0
        );
    }

    protected function interestPaymentIdempotencyPayload(string $slipNo, InterestPaymentAccept $request): array
    {
        return [
            'slip_no' => $slipNo,
            'slip_update_key' => $request->slipUpdateKey,
            'payment_amount' => $request->paymentAmount,
            'accept_account_id' => $request->acceptAccountId,
            'record_debt' => $request->recordDebt,
            'interest_breakdown' => $request->interestBreakdown,
        ];
    }

    /**
     * @param  Collection<int, PawnInterestPayment>  $payments
     */
    protected function assertPaymentsUseCurrency(Collection $payments, int $currencyId): void
    {
        foreach ($payments as $payment) {
            if ($payment->created_account_id === null || $payment->createdAccount === null) {
                throw new InvalidTenantRequest('Interest payment creation account is missing.');
            }

            if ((int) $payment->createdAccount->currency_id !== $currencyId) {
                throw new InvalidTenantRequest('Interest payment accounts must use the same currency.');
            }
        }
    }

    protected function resolveInterestIntervalUnit(PawnLoanContractSlip $slip): string
    {
        $interestType = $slip->interestType;
        $unit = strtolower(trim((string) ($interestType?->code ?: $interestType?->name)));

        return match ($unit) {
            'daily', 'day' => 'day',
            'weekly', 'week' => 'week',
            'monthly', 'month' => 'month',
            'yearly', 'year' => 'year',
            default => ((int) ($interestType?->duration_in_days ?? 1)) === 7 ? 'week' : 'day',
        };
    }

    protected function resolveNextInterestPeriodStart(CarbonImmutable $currentStart, PawnLoanContractSlip $slip): CarbonImmutable
    {
        return match ($this->resolveInterestIntervalUnit($slip)) {
            'day' => $currentStart->addDay(),
            'week' => $currentStart->addWeek(),
            'month' => $currentStart->addMonth(),
            'year' => $currentStart->addYear(),
        };
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function resolveActiveSlipBySlipNo(string $slipNo): PawnLoanContractSlip
    {
        $slip = $this->loanContractSlipRepository->findBySlipNo($slipNo);

        if ($slip === null) {
            throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
        }

        $this->validateActiveSlip($slip);

        return $slip;
    }

    protected function resolveActiveSlipBySlipNoWithLock(string $slipNo): PawnLoanContractSlip
    {
        $slip = $this->loanContractSlipRepository->findBySlipNoWithLock($slipNo);

        if ($slip === null) {
            throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
        }

        $this->validateActiveSlip($slip);

        return $slip;
    }

    protected function validateActiveSlip(PawnLoanContractSlip $slip): void
    {
        if ($slip->status !== 'active') {
            throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
        }
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    protected function dueUnpaidPayments(PawnLoanContractSlip $slip, CarbonImmutable $date): Collection
    {
        return $this->repository->findUnpaidInterestUntilDateBySlipId($slip->id, $date->toDateString());
    }
}
