<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\PartialPrincipalCollectionCreate;
use App\DataObjects\RequestObjects\SlipCompoundScheduleUpdate;
use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Services\BaseTenantService;
use App\Services\Interest\FixedInterestCalculatorService;
use App\Services\PawnModule\LoanContractServices\LookUpService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Services\TenantModule\TenantAuditLogService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PawnInterestProcessService extends BaseTenantService
{
    public function __construct(
        private LoanContractSlipRepository $repository,
        private LookUpService $lookUpService,
        private InterestFlowService $interestFlowService,
        private TenantSettingService $tenantSettingService,
        private TenantLicenseService $tenantLicenseService,
        private TenantUserPermissionService $permissionService,
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private MultiAccountManagement $multiAccountManagement,
        private FinancialAccountTransactionService $financialAccountTransactionService,
        private FixedInterestCalculatorService $fixedInterestCalculatorService,
        private AccountingDayBusinessClock $businessClock,
    ) {}

    public function updateSchedule(string $slipNo, SlipCompoundScheduleUpdate $request): array
    {
        $this->permissionService->authorizePermission('manage_slip_compound_schedule');
        $this->assertCompoundingEnabled();
        $slip = $this->lookUpService->findModelBySlipNo($slipNo);
        $this->validateActiveSlip($slip);

        if ((int) $slip->update_key !== $request->slipUpdateKey) {
            throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
        }

        $data = [
            'compound_schedule_enabled' => $request->enabled,
            'compound_every' => null,
            'compound_every_type' => null,
            'next_compound_at' => null,
            'update_key' => $slip->update_key + 1,
        ];

        if ($request->enabled) {
            if ($request->compoundEvery === null || $request->compoundEvery <= 0 || $request->compoundEveryType === null || $request->nextCompoundAt === null) {
                throw new InvalidTenantRequest('Compound period and next compound date are required.');
            }

            $periodType = $this->normalizeCompoundPeriodType($request->compoundEveryType);
            $this->validateCompoundPeriodWithinSlipLife($slip, $request->compoundEvery, $periodType);
            $nextCompoundAt = CarbonImmutable::parse($request->nextCompoundAt)->startOfDay();
            if ($nextCompoundAt->gt(CarbonImmutable::parse($slip->expire_at)->startOfDay())) {
                throw new InvalidTenantRequest('Next compound date must be before slip expiry.');
            }

            $data['compound_every'] = $request->compoundEvery;
            $data['compound_every_type'] = $periodType;
            $data['next_compound_at'] = $nextCompoundAt;
        }

        return $this->repository->update($slip, $data)->toArray();
    }

    public function compoundBySlipNo(string $slipNo): array
    {
        $this->permissionService->authorizePermission('compound_slip_interest');
        $this->assertCompoundingEnabled();
        $slip = $this->lookUpService->findModelBySlipNo($slipNo);

        return $this->compoundSlip($slip, $this->currentTenantBusinessDate(), false);
    }

    public function collectPartialPrincipal(string $slipNo, PartialPrincipalCollectionCreate $request): array
    {
        $this->permissionService->authorizePermission('collect_partial_principal');
        $this->assertPartialPrincipalEnabled();
        $acceptAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->acceptAccountId);

        return DB::transaction(function () use ($slipNo, $request, $acceptAccount): array {
            $slip = $this->repository->findBySlipNoWithLock($slipNo);
            if ($slip === null) {
                throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
            }
            $this->validateActiveSlip($slip);
            if ((int) $slip->update_key !== $request->slipUpdateKey) {
                throw new AlreadyUpdatedException('This Slip is already updated by others. Please refresh.');
            }
            if ($slip->account_id === null) {
                throw new InvalidTenantRequest('Loan creation account is required.');
            }
            $loanAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount((int) $slip->account_id);
            if ((int) $loanAccount->currency_id !== (int) $acceptAccount->currency_id) {
                throw new InvalidTenantRequest('Loan creation and acceptance accounts must use the same currency.');
            }
            if ($request->amount <= 0 || $request->amount >= (float) $slip->loan_amount) {
                throw new InvalidTenantRequest('Partial principal amount must be greater than zero and lower than remaining principal.');
            }

            $createdBy = $this->resolveCurrentTenantUserId();
            $updatedSlip = $this->repository->update($slip, [
                'loan_amount' => round((float) $slip->loan_amount - $request->amount, 2),
                'update_key' => $slip->update_key + 1,
            ]);

            $accounting = $this->tenantAccountingService->recordTransaction(new TenantAccountingTransactionRecord(
                reference: $updatedSlip,
                description: 'Partial Principal Collection',
                transactionDirection: 'incoming',
                accountingCategory: AccountingCategory::Asset,
                amount: $request->amount,
                createdBy: $createdBy,
                currencyId: (int) $acceptAccount->currency_id,
                exchangeRate: $request->reportingExchangeRate,
            ));
            $this->financialAccountTransactionService->recordPawnPartialPrincipalCollection(
                $acceptAccount,
                $request->amount,
                $updatedSlip->slip_no,
                PawnLoanContractSlip::class,
                'Partial Principal Collection',
                $createdBy,
                $accounting->id,
            );
            $this->interestFlowService->recreateFutureSchedule($updatedSlip, $this->currentTenantBusinessDate()->addDay(), $createdBy);
            $this->logSlipProcess('pawn_principal_collected', $updatedSlip, ['amount' => $request->amount]);

            return [
                'slip' => $updatedSlip->toArray(),
                'collected_amount' => $request->amount,
                'remaining_principal' => (float) $updatedSlip->loan_amount,
            ];
        });
    }

    public function processDueSchedules(): int
    {
        $processed = 0;
        $tenantContext = app(TenantContext::class);
        $originalTenantId = $tenantContext->id();

        try {
            foreach ($this->repository->compoundScheduleTenantIds() as $tenantId) {
                try {
                    $tenantId = (int) $tenantId;
                    $tenantContext->set($tenantId);
                    if (! $this->tenantLicenseService->tenantHasFeature($tenantId, 'advanced_interest_process')) {
                        continue;
                    }
                    if (! $this->interestProcessSettings()['compounding_enabled']) {
                        continue;
                    }

                    $currentDate = $this->businessClock->now($tenantId)->startOfDay();
                    foreach ($this->repository->dueCompoundScheduledSlipsForTenant($tenantId, $currentDate) as $slip) {
                        $result = $this->compoundSlip($slip, $currentDate, true);
                        if (($result['compounded_interest'] ?? 0) > 0) {
                            $processed++;
                        }
                    }
                } catch (Throwable $exception) {
                    Log::error('Pawn interest compounding failed for tenant.', [
                        'tenant_id' => $tenantId,
                        'exception' => $exception,
                    ]);
                }
            }
        } finally {
            $tenantContext->set($originalTenantId);
        }

        return $processed;
    }

    private function compoundSlip(PawnLoanContractSlip $slip, CarbonImmutable $compoundDate, bool $scheduled): array
    {
        $this->validateActiveSlip($slip, $compoundDate);
        if ($slip->account_id === null) {
            throw new InvalidTenantRequest('Loan creation account is required.');
        }

        return DB::transaction(function () use ($slip, $compoundDate, $scheduled): array {
            $lockedSlip = $this->repository->findByIdWithLock($slip->id);
            if ($lockedSlip === null) {
                throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
            }
            $this->validateActiveSlip($lockedSlip, $compoundDate);
            $payments = $this->interestFlowService->unpaidDuePaymentModelsWithLock($lockedSlip, $compoundDate);
            if ($payments->isEmpty()) {
                if ($scheduled && $lockedSlip->compound_every !== null && $lockedSlip->compound_every_type !== null) {
                    $lockedSlip = $this->repository->update($lockedSlip, [
                        'next_compound_at' => $this->fixedInterestCalculatorService->nextPeriodStart($compoundDate, (string) $lockedSlip->compound_every_type, (int) $lockedSlip->compound_every),
                        'update_key' => $lockedSlip->update_key + 1,
                    ]);
                }
                return ['slip' => $lockedSlip->toArray(), 'compounded_interest' => 0.0];
            }

            $amount = round($payments->sum(fn (PawnInterestPayment $payment): float => $this->fixedInterestCalculatorService->remainingInterest((float) $payment->calculated_interest, (float) $payment->payment_amount)), 2);
            $createdBy = $scheduled ? null : $this->resolveCurrentTenantUserId();
            $lastEnd = $payments
                ->map(fn (PawnInterestPayment $payment) => CarbonImmutable::parse($payment->end_period_at)->startOfDay())
                ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->last();

            $this->interestFlowService->markPaymentsCompounded($payments, $compoundDate, $createdBy);

            $update = [
                'loan_amount' => round((float) $lockedSlip->loan_amount + $amount, 2),
                'last_compounded_at' => $compoundDate,
                'update_key' => $lockedSlip->update_key + 1,
            ];
            if ($scheduled && $lockedSlip->compound_every !== null && $lockedSlip->compound_every_type !== null) {
                $update['next_compound_at'] = $this->fixedInterestCalculatorService->nextPeriodStart(
                    $compoundDate,
                    (string) $lockedSlip->compound_every_type,
                    (int) $lockedSlip->compound_every,
                );
            }

            $updatedSlip = $this->repository->update($lockedSlip, $update);
            $this->tenantAccountingService->createInternalTransfer(
                $updatedSlip,
                $scheduled ? 'Scheduled Interest Compounding' : 'Manual Interest Compounding',
                $amount,
                $createdBy,
            );
            $this->interestFlowService->recreateFutureSchedule($updatedSlip, ($lastEnd ?? $compoundDate)->addDay(), $createdBy);
            $this->logSlipProcess('pawn_interest_compounded', $updatedSlip, [
                'amount' => $amount,
                'scheduled' => $scheduled,
                'payment_ids' => $payments->pluck('id')->all(),
            ]);

            return [
                'slip' => $updatedSlip->toArray(),
                'compounded_interest' => $amount,
            ];
        });
    }

    private function validateActiveSlip(PawnLoanContractSlip $slip, ?CarbonImmutable $currentDate = null): void
    {
        $currentDate ??= $this->currentTenantBusinessDate();

        if ($slip->status !== 'active' || CarbonImmutable::parse($slip->expire_at)->lt($currentDate)) {
            throw new InvalidTenantRequest('Loan contract slip not found or inactive.');
        }
    }

    private function assertCompoundingEnabled(): void
    {
        if (! $this->interestProcessSettings()['compounding_enabled']) {
            throw new InvalidTenantRequest('Tenant interest compounding is not enabled.');
        }
    }

    private function assertPartialPrincipalEnabled(): void
    {
        if (! $this->interestProcessSettings()['partial_principal_collection_enabled']) {
            throw new InvalidTenantRequest('Tenant partial principal collection is not enabled.');
        }
    }

    private function interestProcessSettings(): array
    {
        $settings = $this->tenantSettingService->getCurrentTenantInterestProcessSettings();

        return [
            'compounding_enabled' => $settings->compoundingEnabled,
            'partial_principal_collection_enabled' => $settings->partialPrincipalCollectionEnabled,
        ];
    }

    private function normalizeCompoundPeriodType(string $periodType): string
    {
        $periodType = $this->fixedInterestCalculatorService->normalizePeriodType($periodType);
        if ($periodType === 'Year') {
            throw new InvalidTenantRequest('Compound period type must be Day, Week, or Month.');
        }

        return $periodType;
    }

    private function validateCompoundPeriodWithinSlipLife(PawnLoanContractSlip $slip, int $period, string $periodType): void
    {
        $createdAt = CarbonImmutable::parse($slip->created_at)->startOfDay();
        $expireAt = CarbonImmutable::parse($slip->expire_at)->startOfDay();
        $periodEnd = $this->fixedInterestCalculatorService->nextPeriodStart($createdAt, $periodType, $period);

        if ($periodEnd->gte($expireAt)) {
            throw new InvalidTenantRequest('Slip lifetime must be greater than compounding period.');
        }
    }

    private function logSlipProcess(string $event, PawnLoanContractSlip $slip, array $payload): void
    {
        $this->tenantAuditLogService->log($event, PawnLoanContractSlip::class, $slip->id, $payload, $this->resolveCurrentTenantUserId());
    }

    private function currentTenantBusinessDate(): CarbonImmutable
    {
        return $this->businessClock->now($this->resolveCurrentTenantId())->startOfDay();
    }

    private function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }
}
