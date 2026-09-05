<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantDebtPaymentCreate;
use App\DataObjects\ResponseObjects\TenantDebtInterestCalculation;
use App\DataObjects\ResponseObjects\TenantDebtPaymentResult;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantDebtInterestAccrual;
use App\Models\CoreModule\TenantDebtPayment;
use App\Repository\TenantDebtInterestRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class DebtInterestFlowService extends BaseTenantService
{
    public function __construct(
        private TenantDebtInterestRepository $repository,
        private MultiAccountManagement $multiAccountManagement,
        private TenantAccountingTransactionService $tenantAccountingService,
        private FinancialAccountTransactionService $financialAccountTransactionService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private TableIdGenerationService $tableIdGenerationService,
        private CustomerTrustScoreService $customerTrustScoreService,
        private TenantSettingService $tenantSettingService,
    ) {}

    public function initialize(TenantDebt $debt): void
    {
        if (! $debt->apply_interest) {
            return;
        }

        $this->materializeAccruals($debt, CarbonImmutable::parse($debt->created_at)->startOfDay());
    }

    public function calculate(int $debtId): TenantDebtInterestCalculation
    {
        return DB::transaction(function () use ($debtId): TenantDebtInterestCalculation {
            $debt = $this->findDebt($debtId, true);
            $this->materializeAccruals($debt, CarbonImmutable::now()->startOfDay());

            return $this->calculation($debt->refresh()->load(['interestType', 'interestAccruals']));
        });
    }

    public function history(int $debtId): array
    {
        $this->findDebt($debtId);

        return $this->repository->paymentHistory($debtId);
    }

    public function hasPayments(int $debtId): bool
    {
        return $this->repository->paymentExists($debtId);
    }

    public function pay(TenantDebtPaymentCreate $request): TenantDebtPaymentResult
    {
        if ($request->paymentAmount <= 0) {
            throw new InvalidTenantRequest('Payment amount must be greater than zero.');
        }
        if (! in_array($request->allocationOrder, ['interest_first', 'principal_first'], true)) {
            throw new InvalidTenantRequest('Allocation order must be interest_first or principal_first.');
        }

        $idempotency = $this->tenantIdempotencyService->reserveOptional('tenant_debt.payment', $request->idempotencyKey, $request->toArray());
        if ($idempotency !== null && $this->tenantIdempotencyService->isReplay($idempotency)) {
            $this->tenantIdempotencyService->replay($idempotency);
        }

        try {
            $result = DB::transaction(function () use ($request): TenantDebtPaymentResult {
                $debt = $this->findDebt($request->debtId, true);
                if ($debt->is_paid) {
                    throw new InvalidTenantRequest('Debt is already paid.');
                }
                if ($request->debtUpdateKey !== null && (int) $debt->update_key !== $request->debtUpdateKey) {
                    throw new AlreadyUpdatedException('This debt was already updated. Please refresh.');
                }
                if ($debt->created_account_id === null || $debt->createdAccount === null) {
                    throw new InvalidTenantRequest('Debt creation account is required before this debt can be paid.');
                }

                $acceptAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->acceptAccountId);
                if ((int) $acceptAccount->currency_id !== (int) $debt->createdAccount->currency_id) {
                    throw new InvalidTenantRequest('Debt creation and acceptance accounts must use the same currency.');
                }

                $now = CarbonImmutable::now();
                $this->materializeAccruals($debt, $now->startOfDay());
                $accruals = $this->repository->accruals($debt->id, true);
                $interestDue = $this->outstandingInterest($accruals);
                $principalDue = (float) $debt->principal_balance;
                $totalDue = $principalDue + $interestDue;
                if (! $this->tenantSettingService->currentTenantAllowsPartialDebtPayments()
                    && round($request->paymentAmount, 2) < round($totalDue, 2)) {
                    throw new InvalidTenantRequest('Partial debt payments are disabled. Pay the full outstanding amount.');
                }
                $left = $request->paymentAmount;
                $principalPaid = 0.0;
                $interestPaid = 0.0;

                if ($request->allocationOrder === 'interest_first') {
                    $interestPaid = min($left, $interestDue);
                    $left -= $interestPaid;
                    $principalPaid = min($left, $principalDue);
                    $left -= $principalPaid;
                } else {
                    $principalPaid = min($left, $principalDue);
                    $left -= $principalPaid;
                    $interestPaid = min($left, $interestDue);
                    $left -= $interestPaid;
                }

                $payment = $this->repository->createPayment([
                    'tenant_id' => $debt->tenant_id,
                    'code' => $this->tableIdGenerationService->generate('tenant_debt_payments', $now),
                    'debt_id' => $debt->id,
                    'accept_account_id' => $acceptAccount->id,
                    'allocation_order' => $request->allocationOrder,
                    'payment_amount' => $request->paymentAmount,
                    'principal_paid' => $principalPaid,
                    'interest_paid' => $interestPaid,
                    'change_amount' => $left,
                    'payment_at' => $now,
                    'created_by' => Auth::guard('tenantuser')->id(),
                ]);

                $this->allocateInterest($payment, $accruals, $interestPaid);
                $remainingPrincipal = max($principalDue - $principalPaid, 0);
                $remainingInterest = max($interestDue - $interestPaid, 0);
                $isPaid = $remainingPrincipal <= 0.0 && $remainingInterest <= 0.0;
                $debtData = [
                    'principal_balance' => $remainingPrincipal,
                    'is_paid' => $isPaid,
                    'accept_account_id' => $acceptAccount->id,
                    'accepted_by' => Auth::guard('tenantuser')->id(),
                    'update_key' => (int) $debt->update_key + 1,
                ];
                if ($interestDue > 0.0 && $remainingInterest <= 0.0) {
                    $debtData['interest_anchor_at'] = $now;
                    $debtData['last_interest_paid_at'] = $now;
                }
                $updatedDebt = $this->repository->updateDebt($debt, $debtData);

                $this->recordAccounting($payment, $updatedDebt, $acceptAccount, $request->reportingExchangeRate);
                $this->tenantAuditLogService->log('tenant_debt.payment_created', TenantDebtPayment::class, $payment->id, $payment->only([
                    'debt_id', 'allocation_order', 'payment_amount', 'principal_paid', 'interest_paid', 'change_amount',
                ]));
                if ($updatedDebt->customer_id !== null) {
                    $this->customerTrustScoreService->recalculateForCustomer((int) $updatedDebt->customer_id);
                }

                return new TenantDebtPaymentResult(
                    status: $isPaid ? 'paid' : ($left > 0 ? 'change_made' : 'partially_paid'),
                    debtCode: $updatedDebt->code,
                    allocationOrder: $request->allocationOrder,
                    paymentAmount: $request->paymentAmount,
                    principalPaid: $principalPaid,
                    interestPaid: $interestPaid,
                    changeAmount: $left,
                    remainingPrincipal: $remainingPrincipal,
                    remainingInterest: $remainingInterest,
                    isPaid: $isPaid,
                    updateKey: (int) $updatedDebt->update_key,
                    acceptAccountId: $acceptAccount->id,
                );
            });

            if ($idempotency !== null) {
                $this->tenantIdempotencyService->markCompleted($idempotency, 200, ['message' => 'Debt payment processed successfully.', 'data' => $result->toArray()]);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($idempotency !== null) {
                $this->tenantIdempotencyService->markFailed($idempotency);
            }
            throw $exception;
        }
    }

    private function materializeAccruals(TenantDebt $debt, CarbonImmutable $through): void
    {
        if (! $debt->apply_interest || (float) $debt->principal_balance <= 0) {
            return;
        }
        $debt->loadMissing('interestType');
        if ($debt->interestType === null || $debt->interest_rate === null) {
            throw new InvalidTenantRequest('Interest configuration is incomplete for this debt.');
        }

        $rows = $this->repository->accruals($debt->id, true);
        $last = $rows->last();
        $allMaterializedInterestPaid = $last !== null && $rows->every(fn (TenantDebtInterestAccrual $row): bool => (bool) $row->is_paid);
        if ($allMaterializedInterestPaid && $debt->last_interest_paid_at !== null) {
            $start = $this->nextPeriod(CarbonImmutable::parse($debt->interest_anchor_at)->startOfDay(), $debt);
        } elseif ($last !== null) {
            $start = CarbonImmutable::parse($last->end_period_at)->addDay()->startOfDay();
        } else {
            $start = CarbonImmutable::parse($debt->interest_anchor_at ?? $debt->created_at)->startOfDay();
        }

        while ($start->lte($through)) {
            $next = $this->nextPeriod($start, $debt);
            $this->repository->createAccrual([
                'tenant_id' => $debt->tenant_id,
                'debt_id' => $debt->id,
                'principal_amount' => $debt->principal_balance,
                'calculated_interest' => round(((float) $debt->principal_balance * (float) $debt->interest_rate) / 100, 2),
                'paid_amount' => 0,
                'start_period_at' => $start,
                'end_period_at' => $next->subDay(),
                'is_paid' => false,
            ]);
            $start = $next;
        }
    }

    private function nextPeriod(CarbonImmutable $date, TenantDebt $debt): CarbonImmutable
    {
        $unit = strtolower(trim((string) ($debt->interestType?->code ?: $debt->interestType?->name)));
        return match ($unit) {
            'daily', 'day' => $date->addDay(),
            'weekly', 'week' => $date->addWeek(),
            'monthly', 'month' => $date->addMonthNoOverflow(),
            'yearly', 'year' => $date->addYearNoOverflow(),
            default => ((int) ($debt->interestType?->duration_in_days ?? 1)) === 7 ? $date->addWeek() : $date->addDays((int) ($debt->interestType?->duration_in_days ?? 1)),
        };
    }

    private function calculation(TenantDebt $debt): TenantDebtInterestCalculation
    {
        $rows = $debt->interestAccruals->map(fn (TenantDebtInterestAccrual $row): array => [
            'id' => $row->id,
            'update_key' => (int) $row->update_key,
            'principal_amount' => (float) $row->principal_amount,
            'interest_amount' => (float) $row->calculated_interest,
            'paid_amount' => (float) $row->paid_amount,
            'outstanding_amount' => max((float) $row->calculated_interest - (float) $row->paid_amount, 0),
            'start_period_at' => $row->start_period_at?->toISOString(),
            'end_period_at' => $row->end_period_at?->toISOString(),
            'is_paid' => (bool) $row->is_paid,
        ])->all();
        $outstanding = array_sum(array_column($rows, 'outstanding_amount'));

        return new TenantDebtInterestCalculation(
            debtCode: $debt->code,
            debtUpdateKey: (int) $debt->update_key,
            accountId: $debt->created_account_id,
            currentDate: CarbonImmutable::now()->toDateString(),
            originalPrincipal: (string) $debt->amount,
            principalBalance: (string) $debt->principal_balance,
            outstandingInterest: number_format($outstanding, 2, '.', ''),
            totalOutstanding: number_format((float) $debt->principal_balance + $outstanding, 2, '.', ''),
            applyInterest: (bool) $debt->apply_interest,
            interestRate: $debt->interest_rate === null ? null : (string) $debt->interest_rate,
            interestTypeId: $debt->interest_type_id,
            interestTypeName: $debt->interestType?->name,
            allowPartialPayments: $this->tenantSettingService->currentTenantAllowsPartialDebtPayments(),
            interestBreakdown: $rows,
        );
    }

    private function allocateInterest(TenantDebtPayment $payment, $accruals, float $amount): void
    {
        $left = $amount;
        foreach ($accruals as $row) {
            if ($left <= 0) break;
            $outstanding = max((float) $row->calculated_interest - (float) $row->paid_amount, 0);
            if ($outstanding <= 0) continue;
            $allocated = min($left, $outstanding);
            $paid = (float) $row->paid_amount + $allocated;
            $this->repository->updateAccrual($row, ['paid_amount' => $paid, 'is_paid' => $paid >= (float) $row->calculated_interest, 'update_key' => (int) $row->update_key + 1]);
            $this->repository->createAllocation(['tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'accrual_id' => $row->id, 'amount' => $allocated]);
            $left -= $allocated;
        }
    }

    private function outstandingInterest($accruals): float
    {
        return (float) $accruals->sum(fn ($row): float => max((float) $row->calculated_interest - (float) $row->paid_amount, 0));
    }

    private function recordAccounting(TenantDebtPayment $payment, TenantDebt $debt, $account, ?float $rate): void
    {
        $userId = Auth::guard('tenantuser')->id();
        if ((float) $payment->principal_paid > 0) {
            $ledger = $this->tenantAccountingService->recordDebtPayment($debt, "Principal payment for debt: {$debt->description}", (float) $payment->principal_paid, $account->currency, $userId, $rate);
            $this->financialAccountTransactionService->recordDebtPayment($account, (float) $payment->principal_paid, $payment->code, TenantDebtPayment::class, 'Debt principal payment', $userId, $ledger->id);
        }
        if ((float) $payment->interest_paid > 0) {
            $ledger = $this->tenantAccountingService->recordDebtInterestPayment($payment, "Interest payment for debt: {$debt->description}", (float) $payment->interest_paid, $account->currency, $userId, $rate);
            $this->financialAccountTransactionService->recordDebtInterestPayment($account, (float) $payment->interest_paid, $payment->code, TenantDebtPayment::class, 'Debt interest payment', $userId, $ledger->id);
        }
        if ((float) $payment->change_amount > 0) {
            $ledger = $this->tenantAccountingService->recordDebtPaymentChange($debt, "Debt payment change: {$debt->description}", (float) $payment->change_amount, $account->currency, $userId, $rate);
            $this->financialAccountTransactionService->recordAdjustment($account, (float) $payment->change_amount, 'credit', $payment->code, TenantDebtPayment::class, 'Debt payment change', $userId, $ledger->id);
        }
    }

    private function findDebt(int $debtId, bool $lock = false): TenantDebt
    {
        return $this->repository->findDebtById($debtId, $lock) ?? throw new TenantNotFound('Tenant debt not found.');
    }
}
