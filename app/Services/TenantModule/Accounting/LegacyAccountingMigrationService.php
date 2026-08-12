<?php

namespace App\Services\TenantModule\Accounting;

use App\Enums\AccountingCategory;
use App\Enums\FinancialAccountTransactionType;
use App\Models\CoreModule\TenantAccounting;
use App\Models\CoreModule\TenantCapital;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Models\TenantAccountingTransactions;
use App\Repository\Accounting\LegacyAccountingMigrationRepository;
use App\Repository\TenantSettingRepository;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyAccountingMigrationService
{
    public function __construct(
        private LegacyAccountingMigrationRepository $repository,
        private TenantSettingService $tenantSettingService,
        private TenantSettingRepository $tenantSettingRepository,
        private MultiAccountManagement $multiAccountManagement,
        private AccountingDayBusinessClock $businessClock,
    ) {}

    public function migrate(bool $apply): array
    {
        $currencySummary = $this->tenantSettingService->ensureAllTenantCurrencyPreferences(! $apply);
        $accountSummary = $this->multiAccountManagement->ensureDefaults(! $apply);
        $totals = ['scanned' => 0, 'migrated' => 0, 'already_migrated' => 0, 'deleted' => 0, 'internal_skipped' => 0, 'conversion_unavailable' => 0, 'failed' => 0];
        $reports = [];
        $failures = [];

        foreach ($this->repository->tenantIds() as $tenantId) {
            $tenantId = (int) $tenantId;
            $account = $this->repository->defaultAccount($tenantId);
            $reportingIsMmk = $this->reportingCurrencyIsMmk($tenantId, $apply);
            $projectedIncoming = 0.0;
            $projectedOutgoing = 0.0;

            if ($apply && ($account === null || $account->currency?->code !== 'MMK')) {
                $failures[] = ['tenant_id' => $tenantId, 'legacy_id' => null, 'reason' => 'An active MMK default financial account is required.'];
                $totals['failed']++;
                continue;
            }

            foreach ($this->repository->legacyRows($tenantId) as $legacy) {
                $totals['scanned']++;
                $existing = $this->repository->migratedAccounting($tenantId, (int) $legacy->id);
                $hasFinancialPosting = $existing !== null && $this->repository->financialPostingExists($tenantId, (int) $existing->id);

                if ($existing !== null && ($hasFinancialPosting || $legacy->is_deleted || $legacy->transaction_type === 'internal')) {
                    $totals['already_migrated']++;
                    continue;
                }

                if ($legacy->is_deleted) {
                    $totals['deleted']++;
                } elseif ($legacy->transaction_type === 'internal') {
                    $totals['internal_skipped']++;
                } elseif (! $reportingIsMmk) {
                    $totals['conversion_unavailable']++;
                }

                if (! $legacy->is_deleted && $legacy->transaction_type !== 'internal' && ! $hasFinancialPosting) {
                    if ($legacy->transaction_type === 'incoming') {
                        $projectedIncoming += (float) $legacy->amount;
                    } elseif ($legacy->transaction_type === 'outgoing') {
                        $projectedOutgoing += (float) $legacy->amount;
                    }
                }

                if (! $apply) {
                    $totals['migrated']++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($legacy, $account, $reportingIsMmk): void {
                        $accounting = $this->repository->migratedAccounting((int) $legacy->tenant_id, (int) $legacy->id)
                            ?? $this->createAccounting($legacy, $account->currency_id, $reportingIsMmk);

                        if (! $legacy->is_deleted && $legacy->transaction_type !== 'internal'
                            && ! $this->repository->financialPostingExists((int) $legacy->tenant_id, (int) $accounting->id)) {
                            $this->createFinancialPosting($legacy, $accounting, $account->id);
                        }
                    });
                    $totals['migrated']++;
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    $failures[] = ['tenant_id' => $tenantId, 'legacy_id' => $legacy->id, 'reason' => $exception->getMessage()];
                }
            }

            if ($apply) {
                $this->repository->updateAccountBalanceFromLedger($account);
            }

            $old = $this->repository->oldMovement($tenantId);
            $migrated = $account === null ? $this->emptyMovement() : $this->repository->migratedMovement($tenantId, $account->id);
            $full = $account === null ? $this->emptyMovement() : $this->repository->fullMovement($tenantId, $account->id);

            if (! $apply) {
                $migrated = $this->addProjection($migrated, $projectedIncoming, $projectedOutgoing);
                $full = $this->addProjection($full, $projectedIncoming, $projectedOutgoing);
            }

            $reports[] = ['tenant_id' => $tenantId, 'old' => $old, 'migrated' => $migrated, 'full' => $full];
        }

        return compact('currencySummary', 'accountSummary', 'totals', 'reports', 'failures');
    }

    private function createAccounting(TenantAccounting $legacy, int $currencyId, bool $reportingIsMmk): TenantAccountingTransactions
    {
        $occurredAt = $legacy->created_at ?? now();

        return $this->repository->createAccounting([
            'tenant_id' => $legacy->tenant_id,
            'accounting_day_id' => null,
            'business_date' => $occurredAt->copy()->timezone($this->businessClock->timezone((int) $legacy->tenant_id))->toDateString(),
            'transaction_direction' => $legacy->transaction_type,
            'accounting_category' => $this->accountingCategory($legacy)?->value,
            'amount' => $legacy->amount,
            'currency_id' => $currencyId,
            'reporting_amount' => $reportingIsMmk ? $legacy->amount : null,
            'exchange_rate' => $reportingIsMmk ? 1 : null,
            'description' => $legacy->description,
            'reference_id' => $legacy->reference_id,
            'reference_type' => $legacy->reference_type,
            'occurred_at' => $occurredAt,
            'created_by' => $legacy->created_by,
            'legacy_accounting_id' => $legacy->id,
            'update_key' => $legacy->update_key,
            'is_deleted' => $legacy->is_deleted,
            'created_at' => $legacy->created_at,
            'updated_at' => $legacy->updated_at,
        ]);
    }

    private function createFinancialPosting(TenantAccounting $legacy, TenantAccountingTransactions $accounting, int $accountId): void
    {
        $this->repository->createFinancialPosting([
            'tenant_id' => $legacy->tenant_id,
            'financial_account_id' => $accountId,
            'transaction_type' => $this->financialType($legacy)->value,
            'amount' => $legacy->amount,
            'direction' => $legacy->transaction_type === 'incoming' ? 'debit' : 'credit',
            'reference_number' => $this->referenceNumber($legacy),
            'reference_type' => $legacy->reference_type,
            'note' => $legacy->description,
            'created_by' => $legacy->created_by,
            'related_transaction_id' => $accounting->id,
            'created_at' => $legacy->created_at,
            'updated_at' => $legacy->updated_at,
        ]);
    }

    private function accountingCategory(TenantAccounting $legacy): ?AccountingCategory
    {
        if ($legacy->transaction_type === 'internal') {
            return AccountingCategory::Internal;
        }

        return match ($this->referenceClass($legacy->reference_type)) {
            PawnInterestPayment::class => $legacy->transaction_type === 'incoming' ? AccountingCategory::Revenue : AccountingCategory::Asset,
            TenantExpense::class => AccountingCategory::Expense,
            TenantCapital::class => AccountingCategory::Equity,
            PawnLoanContractSlip::class, PawnRedemption::class, TenantDebt::class => AccountingCategory::Asset,
            default => null,
        };
    }

    private function financialType(TenantAccounting $legacy): FinancialAccountTransactionType
    {
        return match ($this->referenceClass($legacy->reference_type)) {
            PawnLoanContractSlip::class => FinancialAccountTransactionType::PawnLoanCreation,
            PawnInterestPayment::class => $legacy->transaction_type === 'incoming' ? FinancialAccountTransactionType::PawnInterestPayment : FinancialAccountTransactionType::Adjustment,
            PawnRedemption::class => $legacy->transaction_type === 'incoming' ? FinancialAccountTransactionType::PawnRedemption : FinancialAccountTransactionType::Adjustment,
            TenantCapital::class => $legacy->transaction_type === 'incoming' ? FinancialAccountTransactionType::CapitalContribution : FinancialAccountTransactionType::CapitalWithdrawal,
            TenantExpense::class => FinancialAccountTransactionType::ExpensePayment,
            TenantDebt::class => $legacy->transaction_type === 'incoming' ? FinancialAccountTransactionType::DebtPayment : FinancialAccountTransactionType::DebtCreation,
            default => FinancialAccountTransactionType::Adjustment,
        };
    }

    private function referenceClass(?string $referenceType): ?string
    {
        $basename = class_basename((string) $referenceType);

        return match ($basename) {
            'PawnLoanContractSlip', 'LoanContractSlip' => PawnLoanContractSlip::class,
            'PawnInterestPayment', 'InterestPayment' => PawnInterestPayment::class,
            'PawnRedemption', 'Redemption' => PawnRedemption::class,
            'TenantCapital', 'Capital' => TenantCapital::class,
            'TenantExpense', 'Expense' => TenantExpense::class,
            'TenantDebt', 'Debt' => TenantDebt::class,
            default => null,
        };
    }

    private function referenceNumber(TenantAccounting $legacy): ?string
    {
        $modelClass = $this->referenceClass($legacy->reference_type);
        if ($modelClass !== null && $legacy->reference_id !== null) {
            $model = $modelClass::query()->withoutGlobalScopes()->find($legacy->reference_id);
            $value = $model?->getAttribute('code') ?? $model?->getAttribute('slip_no') ?? $model?->getAttribute('slip_number');
            if ($value !== null) {
                return (string) $value;
            }
        }

        return $legacy->reference_id === null ? null : (string) $legacy->reference_id;
    }

    private function reportingCurrencyIsMmk(int $tenantId, bool $apply): bool
    {
        $setting = $this->tenantSettingRepository->currencyPreferences($tenantId);

        return $apply ? $setting?->reportingCurrency?->code === 'MMK' : ($setting?->reportingCurrency?->code ?? 'MMK') === 'MMK';
    }

    private function emptyMovement(): array
    {
        return ['incoming' => 0.0, 'outgoing' => 0.0, 'internal' => 0.0, 'balance' => 0.0];
    }

    private function addProjection(array $movement, float $incoming, float $outgoing): array
    {
        $movement['incoming'] += $incoming;
        $movement['outgoing'] += $outgoing;
        $movement['balance'] = $movement['incoming'] - $movement['outgoing'];

        return $movement;
    }
}
