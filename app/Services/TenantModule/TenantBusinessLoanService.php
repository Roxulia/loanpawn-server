<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\DebtCompoundScheduleUpdate;
use App\DataObjects\RequestObjects\TenantBusinessLoanCreate;
use App\DataObjects\RequestObjects\TenantBusinessLoanPaymentCreate;
use App\DataObjects\RequestObjects\TenantBusinessLoanUpdate;
use App\DataObjects\ResponseObjects\TenantBusinessLoanDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantBusinessLoan;
use App\Models\CoreModule\TenantBusinessLoanInterestAccrual;
use App\Models\CoreModule\TenantBusinessLoanPayment;
use App\Repository\TenantBusinessLoanRepository;
use App\Services\BaseTenantService;
use App\Services\Interest\FixedInterestCalculatorService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\TenantContext;
use Throwable;

class TenantBusinessLoanService extends BaseTenantService
{
    public function __construct(
        private TenantBusinessLoanRepository $repository,
        private TenantLenderService $lenderService,
        private TenantUserPermissionService $permissionService,
        private MultiAccountManagement $accountManagement,
        private TenantAccountingTransactionService $accountingService,
        private FinancialAccountTransactionService $accountTransactionService,
        private TenantSettingService $settingService,
        private TenantIdempotencyService $idempotencyService,
        private TenantAuditLogService $auditLogService,
        private TableIdGenerationService $tableIdGenerationService,
        private FixedInterestCalculatorService $interestCalculator,
        private AccountingDayBusinessClock $businessClock,
        private DefaultDataService $defaultDataService,
    ) {}

    public function list(int $perPage, ?string $search): array
    {
        // Authorization and mapping of the business loan directory
        $this->permissionService->authorizeBusinessLoanList();
        $paginator = $this->repository->paginate($perPage, $search);
        return [
            'data' => collect($paginator->items())->map(fn (TenantBusinessLoan $loan) => TenantBusinessLoanDetail::fromModel($loan)->toArray())->all(),
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ];
    }

    public function detail(string $code): TenantBusinessLoanDetail
    {
        $this->permissionService->authorizeBusinessLoanList();
        $loan = $this->find($code);
        $this->materializeAccruals($loan);
        return TenantBusinessLoanDetail::fromModel($this->find($code));
    }

    public function create(TenantBusinessLoanCreate $request): TenantBusinessLoanDetail
    {
        // Validation and idempotent creation of the incoming liability
        $this->permissionService->authorizeBusinessLoanCreate();
        $this->validateInterest($request->applyInterest, $request->interestRate, $request->interestTypeId);
        $idempotency = $this->idempotencyService->reserveOptional('tenant_business_loan.create', $request->idempotencyKey, $request->toArray());
        if ($idempotency !== null && $this->idempotencyService->isReplay($idempotency)) $this->idempotencyService->replay($idempotency);

        try {
            $loan = DB::transaction(function () use ($request): TenantBusinessLoan {
                $lender = $this->lenderService->resolveByCode($request->lenderCode);
                $account = $this->accountManagement->findActiveCurrentTenantAccount($request->receiptAccountId);
                $now = $this->businessClock->now((int) $account->tenant_id);
                $userId = Auth::guard('tenantuser')->id();
                $loan = $this->repository->create([
                    'tenant_id' => $account->tenant_id, 'lender_id' => $lender?->id, 'receipt_account_id' => $account->id,
                    'code' => $this->tableIdGenerationService->generate('tenant_business_loans', $now),
                    'amount' => $request->amount, 'principal_balance' => $request->amount,
                    'apply_interest' => $request->applyInterest, 'interest_rate' => $request->applyInterest ? $request->interestRate : null,
                    'interest_type_id' => $request->applyInterest ? $request->interestTypeId : null,
                    'interest_anchor_at' => $request->applyInterest ? $now : null, 'description' => $request->description,
                    'tag' => $request->tag, 'created_by' => $userId,
                ]);
                $ledger = $this->accountingService->recordBusinessLoanReceipt($loan, $loan->description, (float) $loan->amount, $account->currency, $userId, $request->reportingExchangeRate);
                $this->accountTransactionService->recordBusinessLoanReceipt($account, (float) $loan->amount, $loan->code, TenantBusinessLoan::class, $loan->description, $userId, $ledger->id);
                $this->auditLogService->log('tenant_business_loan.created', TenantBusinessLoan::class, $loan->id, ['loan' => $loan->code]);
                return $loan;
            });
            $detail = TenantBusinessLoanDetail::fromModel($loan);
            if ($idempotency !== null) $this->idempotencyService->markCompleted($idempotency, 201, ['message' => 'Business loan created successfully.', 'data' => $detail->toArray()]);
            return $detail;
        } catch (Throwable $exception) {
            if ($idempotency !== null) $this->idempotencyService->markFailed($idempotency);
            throw $exception;
        }
    }

    public function update(string $code, TenantBusinessLoanUpdate $request): TenantBusinessLoanDetail
    {
        // Optimistic update with accounting reversal for financial changes
        $this->permissionService->authorizeBusinessLoanUpdate();
        $loan = DB::transaction(function () use ($code, $request): TenantBusinessLoan {
            $loan = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Business loan not found.');
            if ($loan->is_paid) throw new InvalidTenantRequest('A settled business loan cannot be edited.');
            if ((int) $loan->update_key !== $request->updateKey) throw new AlreadyUpdatedException('This business loan was already updated. Please refresh.');
            $lender = $this->lenderService->resolveByCode($request->lenderCode);
            $nextAccount = $request->receiptAccountId === null ? $loan->receiptAccount : $this->accountManagement->findActiveCurrentTenantAccount($request->receiptAccountId);
            $amount = $request->amount ?? (float) $loan->amount;
            $financialChange = $amount !== (float) $loan->amount || $nextAccount->id !== $loan->receipt_account_id;
            if ($financialChange && ($loan->apply_interest || $this->repository->hasPayments($loan))) throw new InvalidTenantRequest('Financial values cannot be changed after interest or payments exist.');
            if ($financialChange) {
                $this->accountTransactionService->reverseReference($loan->receiptAccount, $loan->code, TenantBusinessLoan::class, Auth::guard('tenantuser')->id());
                $this->accountingService->deleteForReference($loan);
            }
            $loan = $this->repository->update($loan, ['lender_id' => $lender?->id, 'receipt_account_id' => $nextAccount->id, 'amount' => $amount, 'principal_balance' => $financialChange ? $amount : $loan->principal_balance, 'description' => $request->description, 'tag' => $request->tag, 'update_key' => $loan->update_key + 1]);
            if ($financialChange) {
                $ledger = $this->accountingService->recordBusinessLoanReceipt($loan, $loan->description, $amount, $nextAccount->currency, Auth::guard('tenantuser')->id(), $request->reportingExchangeRate);
                $this->accountTransactionService->recordBusinessLoanReceipt($nextAccount, $amount, $loan->code, TenantBusinessLoan::class, $loan->description, Auth::guard('tenantuser')->id(), $ledger->id);
            }
            $this->auditLogService->log('tenant_business_loan.updated', TenantBusinessLoan::class, $loan->id, ['loan' => $loan->code]);
            return $loan;
        });
        return TenantBusinessLoanDetail::fromModel($loan);
    }

    public function delete(string $code): void
    {
        // Reversal of the receipt before the loan is soft-deleted
        $this->permissionService->authorizeBusinessLoanDelete();
        DB::transaction(function () use ($code): void {
            $loan = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Business loan not found.');
            if ($this->repository->hasPayments($loan)) throw new InvalidTenantRequest('A business loan with payment history cannot be deleted.');
            $this->accountTransactionService->reverseReference($loan->receiptAccount, $loan->code, TenantBusinessLoan::class, Auth::guard('tenantuser')->id());
            $this->accountingService->deleteForReference($loan);
            $this->repository->delete($loan);
            $this->auditLogService->log('tenant_business_loan.deleted', TenantBusinessLoan::class, $loan->id, ['loan' => $loan->code]);
        });
    }

    public function calculation(string $code): array
    {
        $this->permissionService->authorizeBusinessLoanList();
        $loan = $this->find($code);
        $this->materializeAccruals($loan);
        $loan = $this->find($code);
        return $this->calculationPayload($loan);
    }

    public function history(string $code): array
    {
        $this->permissionService->authorizeBusinessLoanList();
        return $this->find($code)->payments->map(fn ($payment): array => [
            'id' => $payment->id, 'code' => $payment->code, 'payment_amount' => (string) $payment->payment_amount,
            'principal_paid' => (string) $payment->principal_paid, 'interest_paid' => (string) $payment->interest_paid,
            'allocation_order' => $payment->allocation_order, 'payment_at' => $payment->payment_at?->toISOString(),
        ])->all();
    }

    public function pay(string $code, TenantBusinessLoanPaymentCreate $request): array
    {
        // Allocation and split accounting of a lender payment
        $this->permissionService->authorizeBusinessLoanUpdate();
        if ($request->amount <= 0) throw new InvalidTenantRequest('Payment amount must be greater than zero.');
        $idempotency = $this->idempotencyService->reserveOptional('tenant_business_loan.payment', $request->idempotencyKey, $request->toArray());
        if ($idempotency !== null && $this->idempotencyService->isReplay($idempotency)) $this->idempotencyService->replay($idempotency);
        try {
            $result = DB::transaction(function () use ($code, $request): array {
                $loan = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Business loan not found.');
                if ($loan->is_paid) throw new InvalidTenantRequest('Business loan is already settled.');
                if ((int) $loan->update_key !== $request->loanUpdateKey) throw new AlreadyUpdatedException('This business loan was already updated. Please refresh.');
                $account = $this->accountManagement->findActiveCurrentTenantAccount($request->paymentAccountId);
                if ((int) $account->currency_id !== (int) $loan->receiptAccount->currency_id) throw new InvalidTenantRequest('Receipt and payment accounts must use the same currency.');
                $this->materializeAccruals($loan);
                $loan = $this->repository->findByCode($code, true);
                $interestDue = $loan->outstanding_interest;
                $principalDue = (float) $loan->principal_balance;
                $totalDue = $principalDue + $interestDue;
                if ($request->amount > $totalDue + 0.001) throw new InvalidTenantRequest('Payment cannot exceed the total outstanding amount.');
                if (! $this->settingService->currentTenantAllowsPartialBusinessLoanPayments() && round($request->amount, 2) !== round($totalDue, 2)) throw new InvalidTenantRequest('Partial business loan payments are disabled. Pay the full outstanding amount.');
                $left = $request->amount;
                if ($request->allocationOrder === 'principal_first') { $principalPaid = min($left, $principalDue); $left -= $principalPaid; $interestPaid = min($left, $interestDue); }
                else { $interestPaid = min($left, $interestDue); $left -= $interestPaid; $principalPaid = min($left, $principalDue); }
                $now = $this->businessClock->now((int) $loan->tenant_id);
                $payment = $this->repository->createPayment(['tenant_id' => $loan->tenant_id, 'business_loan_id' => $loan->id, 'payment_account_id' => $account->id, 'code' => $this->tableIdGenerationService->generate('tenant_business_loan_payments', $now), 'allocation_order' => $request->allocationOrder, 'payment_amount' => $request->amount, 'principal_paid' => $principalPaid, 'interest_paid' => $interestPaid, 'payment_at' => $now, 'created_by' => Auth::guard('tenantuser')->id()]);
                $this->allocateInterest($payment, $loan->interestAccruals, $interestPaid);
                $remainingPrincipal = max($principalDue - $principalPaid, 0);
                $remainingInterest = max($interestDue - $interestPaid, 0);
                $loan = $this->repository->update($loan, ['principal_balance' => $remainingPrincipal, 'is_paid' => $remainingPrincipal <= 0 && $remainingInterest <= 0, 'last_interest_paid_at' => $interestPaid > 0 ? $now : $loan->last_interest_paid_at, 'update_key' => $loan->update_key + 1]);
                $this->recordPaymentAccounting($loan, $payment, $account, $request->reportingExchangeRate);
                $this->auditLogService->log('tenant_business_loan.payment_created', TenantBusinessLoanPayment::class, $payment->id, ['loan' => $loan->code, 'amount' => $request->amount]);
                return ['loan_code' => $loan->code, 'principal_paid' => $principalPaid, 'interest_paid' => $interestPaid, 'remaining_principal' => $remainingPrincipal, 'remaining_interest' => $remainingInterest, 'is_paid' => (bool) $loan->is_paid, 'update_key' => (int) $loan->update_key];
            });
            if ($idempotency !== null) $this->idempotencyService->markCompleted($idempotency, 200, ['message' => 'Business loan payment recorded.', 'data' => $result]);
            return $result;
        } catch (Throwable $exception) { if ($idempotency !== null) $this->idempotencyService->markFailed($idempotency); throw $exception; }
    }

    public function updateCompoundSchedule(string $code, DebtCompoundScheduleUpdate $request): TenantBusinessLoanDetail
    {
        $this->permissionService->authorizeBusinessLoanUpdate();
        if (! $this->settingService->getCurrentTenantInterestProcessSettings()->compoundingEnabled) throw new InvalidTenantRequest('Tenant interest compounding is not enabled.');
        $loan = DB::transaction(function () use ($code, $request): TenantBusinessLoan {
            $loan = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Business loan not found.');
            if ((int) $loan->update_key !== $request->debtUpdateKey) throw new AlreadyUpdatedException('This business loan was already updated. Please refresh.');
            if ($loan->is_paid || ! $loan->apply_interest) throw new InvalidTenantRequest('Business loan is not available for interest compounding.');
            if ($request->enabled && ($request->compoundEvery === null || $request->compoundEveryType === null || $request->nextCompoundAt === null)) throw new InvalidTenantRequest('Compound period and next compound date are required.');
            return $this->repository->update($loan, ['compound_schedule_enabled' => $request->enabled, 'compound_every' => $request->enabled ? $request->compoundEvery : null, 'compound_every_type' => $request->enabled ? $request->compoundEveryType : null, 'next_compound_at' => $request->enabled ? $request->nextCompoundAt : null, 'update_key' => $loan->update_key + 1]);
        });
        return TenantBusinessLoanDetail::fromModel($loan);
    }

    public function compound(string $code): array
    {
        // Open-day controlled capitalization of accrued interest
        $this->permissionService->authorizeBusinessLoanUpdate();
        return $this->compoundLoan($code, false);
    }

    public function processDueSchedules(): int
    {
        // Tenant-isolated processing that defers when accounting is closed
        $processed = 0;
        $context = app(TenantContext::class);
        $originalTenantId = $context->id();
        try {
            foreach ($this->repository->compoundScheduleTenantIds() as $tenantId) {
                $context->set((int) $tenantId);
                $now = $this->businessClock->now((int) $tenantId);
                foreach ($this->repository->dueScheduledLoans((int) $tenantId, $now) as $loan) {
                    try { $this->compoundLoan($loan->code, true); $processed++; }
                    catch (Throwable $exception) { Log::warning('Business loan compounding deferred.', ['tenant_id' => $tenantId, 'loan_id' => $loan->id, 'exception' => $exception->getMessage()]); }
                }
            }
        } finally {
            $context->set($originalTenantId);
        }
        return $processed;
    }

    private function compoundLoan(string $code, bool $scheduled): array
    {
        if (! $this->settingService->getCurrentTenantInterestProcessSettings()->compoundingEnabled) {
            throw new InvalidTenantRequest('Tenant interest compounding is not enabled.');
        }
        return DB::transaction(function () use ($code, $scheduled): array {
            $loan = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Business loan not found.');
            $this->materializeAccruals($loan);
            $loan = $this->repository->findByCode($code, true);
            $amount = $loan->outstanding_interest;
            if ($amount <= 0) throw new InvalidTenantRequest('No business loan interest is available to compound.');
            foreach ($loan->interestAccruals as $row) $this->repository->updateAccrual($row, ['compounded_amount' => $row->calculated_interest - $row->paid_amount, 'compounded_at' => now(), 'is_paid' => true]);
            $nextCompoundAt = $scheduled && $loan->compound_every && $loan->compound_every_type
                ? $this->interestCalculator->nextPeriodStart($this->businessClock->now((int) $loan->tenant_id), $loan->compound_every_type, (int) $loan->compound_every)->utc()
                : $loan->next_compound_at;
            $loan = $this->repository->update($loan, ['principal_balance' => (float) $loan->principal_balance + $amount, 'last_compounded_at' => now(), 'next_compound_at' => $nextCompoundAt, 'update_key' => $loan->update_key + 1]);
            $this->accountingService->createInternalTransfer($loan, $scheduled ? 'Scheduled Business Loan Interest Compounding' : 'Manual Business Loan Interest Compounding', $amount, $scheduled ? null : Auth::guard('tenantuser')->id(), $loan->receiptAccount->currency);
            return ['compounded_interest' => $amount, 'loan' => TenantBusinessLoanDetail::fromModel($loan)->toArray()];
        });
    }

    private function materializeAccruals(TenantBusinessLoan $loan): void
    {
        if (! $loan->apply_interest || $loan->is_paid || (float) $loan->principal_balance <= 0) return;
        $timezone = $this->businessClock->timezone((int) $loan->tenant_id);
        $through = $this->businessClock->now((int) $loan->tenant_id)->setTimezone($timezone)->startOfDay();
        $last = $loan->interestAccruals->sortBy('start_period_at')->last();
        $periodType = $this->interestCalculator->resolveInterestPeriodType($loan->interestType?->code, $loan->interestType?->name, $loan->interestType?->duration_in_days);
        $start = $last ? CarbonImmutable::parse($last->end_period_at)->setTimezone($timezone)->addSecond()->startOfDay() : CarbonImmutable::parse($loan->interest_anchor_at ?? $loan->created_at)->setTimezone($timezone)->startOfDay();
        while ($start->lte($through)) {
            $bounds = $this->interestCalculator->periodBounds($start, $periodType, $timezone);
            $this->repository->createAccrual(['tenant_id' => $loan->tenant_id, 'business_loan_id' => $loan->id, 'principal_amount' => $loan->principal_balance, 'calculated_interest' => $this->interestCalculator->calculate((float) $loan->principal_balance, (float) $loan->interest_rate), 'start_period_at' => $bounds['start']->utc(), 'end_period_at' => $bounds['end']->utc(), 'period_timezone' => $timezone]);
            $start = $bounds['next'];
        }
    }

    private function calculationPayload(TenantBusinessLoan $loan): array
    {
        $rows = $loan->interestAccruals->map(fn ($row): array => ['id' => $row->id, 'principal_amount' => (float) $row->principal_amount, 'interest_amount' => (float) $row->calculated_interest, 'paid_amount' => (float) $row->paid_amount, 'compounded_amount' => (float) $row->compounded_amount, 'outstanding_amount' => $this->rowOutstanding($row), 'start_period_at' => $row->start_period_at?->toISOString(), 'end_period_at' => $row->end_period_at?->toISOString(), 'period_timezone' => $row->period_timezone])->all();
        $interest = array_sum(array_column($rows, 'outstanding_amount'));
        return ['loan_code' => $loan->code, 'loan_update_key' => (int) $loan->update_key, 'account_id' => $loan->receipt_account_id, 'principal_balance' => (string) $loan->principal_balance, 'outstanding_interest' => number_format($interest, 2, '.', ''), 'total_outstanding' => number_format((float) $loan->principal_balance + $interest, 2, '.', ''), 'apply_interest' => (bool) $loan->apply_interest, 'interest_rate' => $loan->interest_rate, 'interest_type_name' => $loan->interestType?->name, 'allow_partial_payments' => $this->settingService->currentTenantAllowsPartialBusinessLoanPayments(), 'compounding_enabled' => $this->settingService->getCurrentTenantInterestProcessSettings()->compoundingEnabled, 'interest_breakdown' => $rows];
    }

    private function allocateInterest(TenantBusinessLoanPayment $payment, $accruals, float $amount): void
    {
        $left = $amount;
        foreach ($accruals as $row) { if ($left <= 0) break; $outstanding = $this->rowOutstanding($row); if ($outstanding <= 0) continue; $allocated = min($left, $outstanding); $paid = (float) $row->paid_amount + $allocated; $this->repository->updateAccrual($row, ['paid_amount' => $paid, 'is_paid' => $this->interestCalculator->remainingInterest((float) $row->calculated_interest, $paid, (float) $row->compounded_amount) <= 0]); $this->repository->createAllocation(['tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'accrual_id' => $row->id, 'amount' => $allocated]); $left -= $allocated; }
    }

    private function recordPaymentAccounting(TenantBusinessLoan $loan, TenantBusinessLoanPayment $payment, $account, ?float $rate): void
    {
        $userId = Auth::guard('tenantuser')->id();
        if ((float) $payment->principal_paid > 0) { $ledger = $this->accountingService->recordBusinessLoanPrincipalPayment($payment, "Principal payment for business loan: {$loan->description}", (float) $payment->principal_paid, $account->currency, $userId, $rate); $this->accountTransactionService->recordBusinessLoanPayment($account, (float) $payment->principal_paid, $payment->code, TenantBusinessLoanPayment::class, 'Business loan principal payment', $userId, $ledger->id); }
        if ((float) $payment->interest_paid > 0) { $ledger = $this->accountingService->recordBusinessLoanInterestPayment($payment, "Interest payment for business loan: {$loan->description}", (float) $payment->interest_paid, $account->currency, $userId, $rate); $this->accountTransactionService->recordExpensePayment($account, (float) $payment->interest_paid, $payment->code, TenantBusinessLoanPayment::class, 'Business loan interest expense', $userId, $ledger->id); }
    }

    private function validateInterest(bool $enabled, ?float $rate, ?int $typeId): void
    {
        if ($enabled && ($rate === null || $rate <= 0 || $typeId === null)) throw new InvalidTenantRequest('Interest rate and type are required when interest is enabled.');
        if ($enabled && $this->defaultDataService->getInterestTypeById($typeId) === null) throw new TenantNotFound('Interest type not found.');
    }
    private function rowOutstanding(TenantBusinessLoanInterestAccrual $row): float { return $this->interestCalculator->remainingInterest((float) $row->calculated_interest, (float) $row->paid_amount, (float) $row->compounded_amount); }
    private function find(string $code): TenantBusinessLoan { return $this->repository->findByCode($code) ?? throw new TenantNotFound('Business loan not found.'); }
}
