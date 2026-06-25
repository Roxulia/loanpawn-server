<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\InterestPaymentAccept;
use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\DataObjects\ResponseObjects\InterestCalculationResult;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryItem;
use App\DataObjects\ResponseObjects\InterestPaymentHistoryListPage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Repository\PawnInterestPaymentRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Services\TenantModule\TenantAccountingService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantIdempotencyService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Exceptions\TenantNotFound;
use Throwable;

class InterestFlowService extends BaseTenantService
{
    public function __construct(
        private LoanContractSlipRepository $loanContractSlipRepository,
        private PawnInterestPaymentRepository $repository,
        private TenantAccountingService $tenantAccountingService,
        private TenantDebtService $tenantDebtService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private CustomerTrustScoreService $customerTrustScoreService,
    ) {
    }

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
        $currentDate = CarbonImmutable::now()->startOfDay();
        $payments = $this->dueUnpaidPayments($slip, $currentDate);
        $interestBreakdown = $payments
            ->map(fn (PawnInterestPayment $payment): InterestBreakDown => InterestBreakDown::fromModel($payment))
            ->all();

        return InterestCalculationResult::fromValues(
            slipNo: $slip->slip_no,
            slipUpdateKey: $slip->update_key,
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

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'interest_payment.pay',
            $request->idempotencyKey,
            $this->interestPaymentIdempotencyPayload($slipNo, $request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $currentDate = CarbonImmutable::now()->startOfDay();

        try {
            $result = DB::transaction(function () use ($slipNo, $request, $currentDate): array {
                $slip = $this->resolveActiveSlipBySlipNoWithLock($slipNo);
                if($slip->update_key !== $request->slipUpdateKey)
                {
                    throw new AlreadyUpdatedException("This Slip is already updated by others.Please refresh");
                }
                $payments = $this->repository->findUnpaidInterestUntilDateBySlipIdWithLock($slip->id, $currentDate->toDateString());

                if ($payments->isEmpty()) {
                    throw new InvalidTenantRequest('No unpaid interest payment found for this slip.');
                }
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
                        'created_by' => $this->resolveCurrentTenantUserId(),
                        'payment_date' => $currentDate->toDateString(),
                        'payment_amount' => $appliedAmount,
                        'change_amount' => $changeAmount,
                        'update_key' => $payment->update_key+1
                    ]);

                    $this->recordInterestPaymentAccounting($updatedPayment);
                    $this->logInterestPaymentUpdate($updatedPayment);

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
                        'change_amount' => $changeAmount,
                        'notes' => trim(($lastPaidPayment->notes ? $lastPaidPayment->notes.PHP_EOL : '').'Change amount: '.$changeAmount),
                    ]);

                    $this->recordInterestPaymentChangeAccounting($lastPaidPayment);
                }

                foreach ($this->repository->findPaymentsAfterPaymentWithLock($slip->id, $lastPaidPayment) as $futurePayment) {
                    $this->repository->delete($futurePayment);
                }

                $updatedSlip = $this->loanContractSlipRepository->update($slip, [
                    'last_interest_paid_date' => $currentDate->toDateString(),
                    'last_interest_added_date' => $currentDate->toDateString(),
                    'expire_date' => $this->calculateExpireDate($currentDate, (int) $slip->expiry_quota, (string) $slip->expiry_quota_type)->toDateString(),
                    'update_key' => $slip->update_key+1
                ]);

                $nextScheduleStart = $this->calculateNextScheduleStartDate($currentDate, (string) $slip->expiry_quota_type);

                $this->createLoanContractInterestPayments(
                    $updatedSlip,
                    $nextScheduleStart,
                    CarbonImmutable::parse($updatedSlip->expire_date)->startOfDay(),
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
        DB::transaction(function () use ($slip, $isRedemptionProcess): void {
            $this->loanContractSlipRepository->findByIdWithLock($slip->id);
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, CarbonImmutable::now()->toDateString());

            foreach ($payments as $payment) {
                if ($payment->is_paid) {
                    continue;
                }

                $payment = $this->repository->update($payment, [
                    'is_paid' => true,
                    'created_by' => $this->resolveCurrentTenantUserId(),
                    'payment_date' => CarbonImmutable::now()->toDateString(),
                    'payment_amount' => (float) $payment->calculated_interest,
                    'change_amount' => 0,
                ]);

                $this->recordInterestPaymentAccounting($payment);
                $this->logInterestPaymentUpdate($payment);
            }

            if (! $isRedemptionProcess) {
                return;
            }

            $futurePayments = $this->repository->findInterestAfterDateBySlipIdWithLock($slip->id, CarbonImmutable::now()->toDateString());

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
            'start_date' => optional($payment->start_period)->toDateString(),
            'end_date' => optional($payment->end_period)->toDateString(),
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

    public function settleForRedemption(PawnLoanContractSlip $slip, CarbonImmutable $paymentDate, ?int $createdBy = null,array $interests=[]): void
    {
        DB::transaction(function () use ($slip, $paymentDate, $createdBy,$interests): void {
            $this->loanContractSlipRepository->findByIdWithLock($slip->id);
            $payments = $this->repository->findInterestUntilDateBySlipIdWithLock($slip->id, $paymentDate->toDateString());
            $requestedBreakdownById = collect($interests)->keyBy('id');

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
                    'created_by' => $createdBy,
                    'payment_date' => $paymentDate->toDateString(),
                    'payment_amount' => (float) $payment->calculated_interest,
                    'change_amount' => 0,
                    'update_key' => $payment->update_key+1
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
            CarbonImmutable::parse($slip->created_date)->startOfDay(),
            CarbonImmutable::parse($slip->expire_date)->startOfDay(),
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

    protected function calculateNextScheduleStartDate(CarbonInterface $currentDate, string $quotaType): CarbonImmutable
    {
        return $this->calculateExpireDate($currentDate, 1, $quotaType);
    }

    public function calculateEndDate(CarbonInterface $currentDate, PawnLoanContractSlip $slip): CarbonImmutable
    {
        $date = CarbonImmutable::parse($currentDate)->startOfDay();
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
                'payment_amount' => 0,
                'change_amount' => 0,
                'calculated_interest' => $interestAmount,
                'payment_date' => null,
                'created_by' => null,
                'start_period' => $currentDate->toDateString(),
                'end_period' => $endDate->toDateString(),
                'is_paid' => false,
            ]);

            $this->tenantAuditLogService->log(
                'pawn_interest_payment.created',
                PawnInterestPayment::class,
                $payment->id,
                [
                    'slip_id' => $slip->id,
                    'start_period' => $payment->start_period?->toDateString(),
                    'end_period' => $payment->end_period?->toDateString(),
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
                'payment_amount' => 0,
                'change_amount' => 0,
                'calculated_interest' => $interestAmount,
                'payment_date' => null,
                'notes' => null,
                'created_by' => $createdBy,
                'start_period' => $currentStart->toDateString(),
                'end_period' => $endDate->toDateString(),
                'is_paid' => false,
            ]);

            $this->tenantAuditLogService->log(
                'pawn_interest_payment.created',
                PawnInterestPayment::class,
                $payment->id,
                [
                    'slip_id' => $slip->id,
                    'start_period' => $payment->start_period?->toDateString(),
                    'end_period' => $payment->end_period?->toDateString(),
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
            $lastPayment->end_period,
            $lastPayment->payment_date,
            $lastPayment->start_period,
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

        $this->tenantDebtService->createForCurrentTenant(new TenantDebtCreate(
            amount: $remainingInterest,
            description: 'Remaining interest from payment ID: '.$payment->id,
            slipId: $slip->id,
            tag: 'InterestPayment',
            createdBy: $this->resolveCurrentTenantUserId(),
            internalOperation: true,
        ));

        return $remainingInterest;
    }

    protected function recordInterestPaymentAccounting(PawnInterestPayment $payment): void
    {
        if ((float) $payment->payment_amount <= 0.0) {
            return;
        }

        $this->tenantAccountingService->create(new TenantAccountingCreate(
            description: 'Interest Payment Transaction',
            transactionType: 'incoming',
            amount: (float) $payment->payment_amount,
            createdBy: $payment->created_by,
            referenceId: $payment->id,
            referenceType: PawnInterestPayment::class
        ));
    }

    protected function recordInterestPaymentChangeAccounting(PawnInterestPayment $payment): void
    {
        if ((float) $payment->change_amount <= 0.0) {
            return;
        }

        $this->tenantAccountingService->create(new TenantAccountingCreate(
            description: 'Interest Payment Change Transaction',
            transactionType: 'outgoing',
            amount: (float) $payment->change_amount,
            createdBy: $payment->created_by,
            referenceId: $payment->id,
            referenceType: PawnInterestPayment::class
        ));
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
     * @param array<int, array<string, mixed>> $interestBreakdown
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
            'record_debt' => $request->recordDebt,
            'interest_breakdown' => $request->interestBreakdown,
        ];
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
