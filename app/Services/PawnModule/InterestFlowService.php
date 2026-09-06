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
use App\Models\FinancialAccount;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Repository\PawnInterestPaymentRepository;
use App\Services\BaseTenantService;
use App\Services\Interest\FixedInterestCalculatorService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantIdempotencyService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
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
        private FixedInterestCalculatorService $fixedInterestCalculatorService,
        private AccountingDayBusinessClock $businessClock,
        private ExpirationService $expirationService,
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
        $currentAt = $this->businessClock->now((int) $slip->tenant_id);
        // Lazily restore any accruals missed by the scheduled job.
        $this->materializeDueInterestRows($slip, $currentAt);
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

        $tenantId = $this->resolveCurrentTenantId();
        $currentAt = $this->businessClock->now($tenantId);
        $currentDate = $currentAt->startOfDay();

        try {
            $result = DB::transaction(function () use ($slipNo, $request, $currentAt, $currentDate, $financialAccount): array {
                $slip = $this->resolveActiveSlipBySlipNoWithLock($slipNo);
                // Lazily restore any due rows before validating the submitted breakdown.
                $this->materializeDueInterestRows($slip, $currentAt);
                if ($slip->update_key !== $request->slipUpdateKey) {
                    throw new AlreadyUpdatedException('This Slip is already updated by others.Please refresh');
                }
                $payments = $this->repository->findUnpaidInterestUntilDateBySlipIdWithLock($slip->id, $currentAt);

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

                // Move the renewed contract window by the configured interest interval.
                $renewalWindow = $this->calculateRenewalWindow($slip, $currentDate);
                $updatedSlip = $this->loanContractSlipRepository->update($slip, [
                    'last_interest_paid_at' => $currentAt,
                    'last_interest_added_at' => $currentAt,
                    'expire_at' => $renewalWindow['expire_at'],
                    'update_key' => $slip->update_key + 1,
                ]);
                // Create exactly one next-period row as part of the payment transaction.
                $this->createSingleInterestRow(
                    $updatedSlip,
                    $renewalWindow['start_at'],
                    $renewalWindow['expire_at'],
                    $this->resolveCurrentTenantUserId(),
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
            // Lazily restore due interest before accepting it.
            $this->materializeDueInterestRows($slip, $paymentAt);
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $paymentAt);
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

            $futurePayments = $this->repository->findInterestAfterDateBySlipIdWithLock($slip->id, $paymentAt);

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
        $slip = $this->expirationService->refreshExpiration($slip);
        $this->validateActiveSlip($slip);
        // Lazily restore any rows that should already exist.
        $this->materializeDueInterestRows($slip, $this->businessClock->now((int) $slip->tenant_id));

        $payments = $this->dueUnpaidPayments($slip, $this->businessClock->now((int) $slip->tenant_id));
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
        // Ensure redemption and reporting reads include missed scheduled accruals.
        $this->materializeDueInterestRows($slip, $targetDate);

        return $this->repository->findInterestUntilDateBySlipId($slip->id, $targetDate)
            ->map(fn (PawnInterestPayment $payment): InterestPaymentHistoryItem => InterestPaymentHistoryItem::fromModel($payment))
            ->all();
    }

    /**
     * @return InterestPaymentHistoryItem[]
     */
    public function getInterestBreakdownUntilNowWithLock(PawnLoanContractSlip $slip, ?CarbonImmutable $date = null): array
    {
        $targetDate = $date ?? CarbonImmutable::now()->startOfDay();
        // Ensure locked redemption reads include missed scheduled accruals.
        $this->materializeDueInterestRows($slip, $targetDate);

        return $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $targetDate)
            ->map(fn (PawnInterestPayment $payment): InterestPaymentHistoryItem => InterestPaymentHistoryItem::fromModel($payment))
            ->all();
    }

    public function settleForRedemption(PawnLoanContractSlip $slip, CarbonImmutable $paymentDate, FinancialAccount $acceptAccount, ?int $createdBy = null, array $interests = []): void
    {
        DB::transaction(function () use ($slip, $paymentDate, $acceptAccount, $createdBy, $interests): void {
            $this->loanContractSlipRepository->findByIdWithLock($slip->id);
            // Ensure redemption settles every interest row due through its payment date.
            $this->materializeDueInterestRows($slip, $paymentDate, $createdBy);
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $paymentDate);
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

            foreach ($this->repository->findInterestAfterDateBySlipIdWithLock($slip->id, $paymentDate) as $futurePayment) {
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

        // Create only the first period instead of pre-scheduling the full slip lifetime.
        $this->createSingleInterestRow(
            $slip,
            CarbonImmutable::parse($slip->created_at)->startOfDay(),
            CarbonImmutable::parse($slip->expire_at)->startOfDay(),
            $createdBy,
        );
    }

    public function materializeDueInterestRows(
        PawnLoanContractSlip $slip,
        CarbonInterface $through,
        ?int $createdBy = null,
    ): int
    {
        if ($slip->is_deleted || ! in_array(strtolower((string) $slip->status), ['active', 'expired'], true)
            || $slip->interest_type_id === null || $slip->expire_at === null || (float) $slip->interest_rate <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($slip, $through, $createdBy): int {
            $lockedSlip = $this->loanContractSlipRepository->findByIdWithLock((int) $slip->id);
            if ($lockedSlip === null) return 0;

            $timezone = $this->businessClock->timezone((int) $lockedSlip->tenant_id);
            $throughDate = CarbonImmutable::parse($through)->setTimezone($timezone)->startOfDay();
            $expire = CarbonImmutable::parse($lockedSlip->expire_at)->setTimezone($timezone)->startOfDay();
            $existingRows = $this->repository->allForSlipWithLock((int) $lockedSlip->id);
            // A completed payment resets accrual to the following local day.
            $allRowsSettled = $existingRows->isNotEmpty()
                && $existingRows->every(fn (PawnInterestPayment $row): bool => (bool) $row->is_paid);
            $latestPaymentMatchesSlipAnchor = $lockedSlip->last_interest_paid_at !== null
                && $existingRows->contains(function (PawnInterestPayment $row) use ($lockedSlip): bool {
                    return $row->payment_at !== null
                        && CarbonImmutable::parse($row->payment_at)->equalTo(CarbonImmutable::parse($lockedSlip->last_interest_paid_at));
                });

            if ($allRowsSettled && $latestPaymentMatchesSlipAnchor) {
                $start = CarbonImmutable::parse($lockedSlip->last_interest_paid_at)
                    ->setTimezone($timezone)
                    ->addDay()
                    ->startOfDay();
            } elseif ($existingRows->isNotEmpty()) {
                $lastRow = $existingRows->last();
                $start = CarbonImmutable::parse($lastRow->end_period_at)
                    ->setTimezone($lastRow->period_timezone ?: $timezone)
                    ->addSecond()
                    ->setTimezone($timezone)
                    ->startOfDay();
            } else {
                $start = CarbonImmutable::parse($lockedSlip->created_at)->setTimezone($timezone)->startOfDay();
            }

            $interestInterval = $this->resolveInterestInterval($lockedSlip);
            $useInclusiveBoundary = in_array($interestInterval['type'], ['Day', 'Week'], true);
            $interestAmount = $this->fixedInterestCalculatorService->calculate(
                (float) $lockedSlip->loan_amount,
                (float) $lockedSlip->interest_rate,
            );
            $existingStarts = $existingRows->mapWithKeys(fn (PawnInterestPayment $row): array => [
                CarbonImmutable::parse($row->start_period_at)->utc()->format('Y-m-d H:i:s') => true,
            ]);
            $created = 0;

            // Materialize every missing period that is due, while respecting expiry.
            while ($start->lte($throughDate) && ($useInclusiveBoundary ? $start->lte($expire) : $start->lt($expire))) {
                $bounds = $this->fixedInterestCalculatorService->periodBounds(
                    $start,
                    $interestInterval['type'],
                    $timezone,
                    $interestInterval['count'],
                );
                $end = $bounds['end']->min($expire->endOfDay()->setMicrosecond(0));
                $startKey = $start->utc()->format('Y-m-d H:i:s');

                // Keep scheduler and lazy retries idempotent for an already materialized start.
                if (! $existingStarts->has($startKey)) {
                    $this->createScheduleRow($lockedSlip, [
                        'start_period_at' => $start->utc(),
                        'end_period_at' => $end->utc(),
                        'calculated_interest' => $interestAmount,
                        'period_timezone' => $timezone,
                    ], $createdBy);
                    $existingStarts->put($startKey, true);
                    $created++;
                }
                $start = $bounds['next'];
            }

            if ($created > 0) {
                // Record when interest was most recently materialized without changing payment history.
                $this->loanContractSlipRepository->update($lockedSlip, [
                    'last_interest_added_at' => CarbonImmutable::parse($through)->utc(),
                ]);
            }

            return $created;
        });
    }

    public function calculateExpireDate(CarbonInterface $currentDate, int $quota, string $quotaType): CarbonImmutable
    {
        return $this->fixedInterestCalculatorService->nextPeriodStart($currentDate, $quotaType, $quota);
    }

    /** @return array{start_at: CarbonImmutable, expire_at: CarbonImmutable} */
    public function calculateRenewalWindow(PawnLoanContractSlip $slip, CarbonInterface $paymentDate): array
    {
        $timezone = $slip->tenant_id === null
            ? config('app.timezone')
            : $this->businessClock->timezone((int) $slip->tenant_id);
        $paymentDate = CarbonImmutable::parse($paymentDate)->setTimezone($timezone)->startOfDay();
        $interestInterval = $this->resolveInterestInterval($slip);
        // Renew from the next configured interest boundary at tenant-local midnight.
        $startAt = $this->fixedInterestCalculatorService->nextPeriodStart(
            $paymentDate,
            $interestInterval['type'],
            $interestInterval['count'],
        );

        return [
            'start_at' => $startAt,
            'expire_at' => $this->calculateExpireDate(
                $startAt,
                (int) $slip->expiry_quota,
                (string) $slip->expiry_quota_type,
            ),
        ];
    }

    /** @return array<int, array{start_period_at: CarbonImmutable, end_period_at: CarbonImmutable, calculated_interest: float}> */
    public function expectedScheduleRows(
        PawnLoanContractSlip $slip,
        CarbonImmutable $startDate,
        CarbonImmutable $expireDate,
    ): array {
        $slip->loadMissing('interestType');
        $interestAmount = $this->fixedInterestCalculatorService->calculate((float) $slip->loan_amount, (float) $slip->interest_rate);
        $timezone = $this->businessClock->timezone((int) $slip->tenant_id);
        $currentStart = $startDate->setTimezone($timezone)->startOfDay();
        $expireAt = $expireDate->setTimezone($timezone)->startOfDay();
        $interestInterval = $this->resolveInterestInterval($slip);
        $useInclusiveBoundary = in_array($interestInterval['type'], ['Day', 'Week'], true);
        $rows = [];

        while ($useInclusiveBoundary ? $currentStart->lte($expireAt) : $currentStart->lt($expireAt)) {
            $bounds = $this->fixedInterestCalculatorService->periodBounds(
                $currentStart,
                $interestInterval['type'],
                $timezone,
                $interestInterval['count'],
            );
            $endDate = $bounds['end'];
            $expireEnd = $expireAt->endOfDay()->setMicrosecond(0);
            if ($endDate->gt($expireEnd)) {
                $endDate = $expireEnd;
            }

            $rows[] = [
                'start_period_at' => $currentStart->utc(),
                'end_period_at' => $endDate->utc(),
                'calculated_interest' => $interestAmount,
                'period_timezone' => $timezone,
            ];

            if ($endDate->gte($expireEnd)) {
                break;
            }
            $currentStart = $bounds['next'];
        }

        return $rows;
    }

    public function unpaidDuePaymentModelsWithLock(PawnLoanContractSlip $slip, CarbonImmutable $date): Collection
    {
        // Lazily restore rows missed by scheduling before compounding queries them.
        $this->materializeDueInterestRows($slip, $date);
        return $this->repository->findUnpaidInterestUntilDateBySlipIdWithLock($slip->id, $date);
    }

    public function markPaymentsCompounded(Collection $payments, CarbonImmutable $compoundedAt, ?int $createdBy = null): void
    {
        foreach ($payments as $payment) {
            $this->repository->update($payment, [
                'is_paid' => true,
                'payment_at' => $compoundedAt,
                'payment_amount' => 0,
                'change_amount' => 0,
                'created_by' => $createdBy,
                'notes' => trim(($payment->notes ? $payment->notes.PHP_EOL : '').'Compounded into principal at '.$compoundedAt->toDateTimeString()),
                'update_key' => $payment->update_key + 1,
            ]);
        }
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

    /** @return array{type: string, count: int} */
    protected function resolveInterestInterval(PawnLoanContractSlip $slip): array
    {
        $interestCode = strtolower(trim((string) ($slip->interestType?->code ?: $slip->interestType?->name)));

        return match ($interestCode) {
            'daily', 'day' => ['type' => 'Day', 'count' => 1],
            'weekly', 'week' => ['type' => 'Week', 'count' => 1],
            'monthly', 'month' => ['type' => 'Month', 'count' => 1],
            'yearly', 'year' => ['type' => 'Year', 'count' => 1],
            default => ['type' => 'Day', 'count' => max(1, (int) ($slip->interestType?->duration_in_days))],
        };
    }

    private function createSingleInterestRow(
        PawnLoanContractSlip $slip,
        CarbonImmutable $startDate,
        CarbonImmutable $expireDate,
        ?int $createdBy,
    ): void {
        // Reuse the established period-end and expiry-boundary calculation.
        $rows = $this->expectedScheduleRows($slip, $startDate, $expireDate);

        if ($rows !== []) {
            $this->createScheduleRow($slip, $rows[0], $createdBy);
        }
    }

    private function createScheduleRow(PawnLoanContractSlip $slip, array $row, ?int $createdBy): PawnInterestPayment
    {
        $payment = $this->repository->create([
            'tenant_id' => $slip->tenant_id,
            'slip_id' => $slip->id,
            'created_account_id' => $slip->account_id,
            'payment_amount' => 0,
            'change_amount' => 0,
            'calculated_interest' => $row['calculated_interest'],
            'notes' => null,
            'created_by' => $createdBy,
            'start_period_at' => $row['start_period_at'],
            'end_period_at' => $row['end_period_at'],
            'period_timezone' => $row['period_timezone'],
            'is_paid' => false,
        ]);
        $this->tenantAuditLogService->log('pawn_interest_payment.created', PawnInterestPayment::class, $payment->id, [
            'slip_id' => $slip->id,
            'start_period_at' => $payment->start_period_at?->toISOString(),
            'end_period_at' => $payment->end_period_at?->toISOString(),
            'period_timezone' => $payment->period_timezone,
            'calculated_interest' => (float) $payment->calculated_interest,
        ], $createdBy);

        return $payment;
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

        $slip = $this->expirationService->refreshExpiration($slip);
        $this->validateActiveSlip($slip);

        return $slip;
    }

    protected function resolveActiveSlipBySlipNoWithLock(string $slipNo): PawnLoanContractSlip
    {
        $slip = $this->loanContractSlipRepository->findBySlipNoWithLock($slipNo);

        if ($slip === null) {
            throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
        }

        $slip = $this->expirationService->refreshExpiration($slip);
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
        return $this->repository->findUnpaidInterestUntilDateBySlipId($slip->id, $date);
    }
}
