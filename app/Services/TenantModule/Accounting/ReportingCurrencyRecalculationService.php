<?php

namespace App\Services\TenantModule\Accounting;

use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\ReportingExchangeRateRequired;
use App\DataObjects\ResponseObjects\ReportingExchangeRateQuoteResource;
use App\Jobs\RecalculateReportingCurrencyJob;
use App\Models\CoreModule\TenantSetting;
use App\Models\ReportingCurrencyRecalculation;
use App\Repository\Accounting\ReportingCurrencyRecalculationRepository;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Support\TenantScopedCacheKeys;
use App\Support\TenantContext;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantCurrencyService;
use App\Services\TenantModule\TenantUserNotificationService;

class ReportingCurrencyRecalculationService
{
    public function __construct(
        private ReportingCurrencyRecalculationRepository $repository,
        private ReportingExchangeRateService $exchangeRates,
        private TenantAccountingMonthlySummaryService $accountingSummaries,
        private FinancialAccountMonthlySummaryService $accountSummaries,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private Messages $messages,
        private TenantContext $tenantContext,
        private TenantAccountingDayService $accountingDayService,
        private TenantCurrencyService $tenantCurrencyService,
        private TenantUserNotificationService $notificationService,
    ) {}

    public function start(int $tenantId, int $tenantUserId, int $previousCurrencyId, int $requestedCurrencyId, string $businessDate): ?ReportingCurrencyRecalculation
    {
        if ($previousCurrencyId === $requestedCurrencyId) {
            return null;
        }

        if ($this->repository->activeForTenant($tenantId, true) !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceReportingCurrencyChangeAlreadyActive));
        }

        $currentDate = CarbonImmutable::parse($businessDate);
        $recalculation = $this->repository->create([
            'tenant_id' => $tenantId,
            'initiated_by_tenant_user_id' => $tenantUserId,
            'previous_reporting_currency_id' => $previousCurrencyId,
            'requested_reporting_currency_id' => $requestedCurrencyId,
            'window_start' => $currentDate->startOfMonth()->subMonthsNoOverflow(2)->toDateString(),
            'window_end' => $currentDate->toDateString(),
            'status' => 'queued',
            'missing_rates' => null,
            'queued_at' => now(),
        ]);

        $this->notificationService->recordReportingCurrencyStatus($recalculation);

        RecalculateReportingCurrencyJob::dispatch($recalculation->id)->afterCommit();

        return $recalculation;
    }

    public function activeForTenant(int $tenantId): ?ReportingCurrencyRecalculation
    {
        return $this->repository->activeForTenant($tenantId);
    }

    public function effectiveCurrencyId(int $tenantId, bool $lock = false): int
    {
        $setting = $this->repository->currencyPreferences($tenantId, $lock);
        $active = $this->repository->activeForTenant($tenantId, $lock);

        if ($active !== null) {
            return (int) $active->previous_reporting_currency_id;
        }

        return (int) $setting->reporting_currency_id;
    }

    public function reportingValues(
        int $tenantId,
        ?int $transactionCurrencyId,
        float $amount,
        string $businessDate,
        ?float $providedExchangeRate,
        ?float $providedReportingAmount,
    ): array {
        $effectiveCurrencyId = $this->effectiveCurrencyId($tenantId, true);

        if ($transactionCurrencyId === null) {
            return [$providedReportingAmount, $providedExchangeRate];
        }

        if ($transactionCurrencyId === $effectiveCurrencyId) {
            return [$amount, 1.0];
        }

        $conversion = $this->exchangeRates->conversion(
            $tenantId,
            $transactionCurrencyId,
            $effectiveCurrencyId,
            $businessDate,
        );

        if ($conversion !== null) {
            return [$amount * $conversion['multiplier'], $conversion['multiplier']];
        }

        if ($providedExchangeRate !== null && $providedExchangeRate > 0) {
            return [$amount * $providedExchangeRate, $providedExchangeRate];
        }

        throw new ReportingExchangeRateRequired;
    }

    public function quote(int $fromCurrencyId, ?int $toCurrencyId = null): ReportingExchangeRateQuoteResource
    {
        $tenantId = $this->tenantContext->id() ?? throw new InvalidTenantRequest;
        $businessDate = $this->accountingDayService->currentBusinessDate();
        $targetCurrencyId = $toCurrencyId ?? $this->effectiveCurrencyId($tenantId);
        $conversion = $this->exchangeRates->conversion($tenantId, $fromCurrencyId, $targetCurrencyId, $businessDate);
        $fromCurrency = $this->tenantCurrencyService->findActiveVisibleForTenant($tenantId, $fromCurrencyId);
        $toCurrency = $this->tenantCurrencyService->findActiveVisibleForTenant($tenantId, $targetCurrencyId);

        return new ReportingExchangeRateQuoteResource(
            fromCurrencyId: $fromCurrencyId,
            toCurrencyId: $targetCurrencyId,
            fromCurrencyCode: $fromCurrency->code,
            toCurrencyCode: $toCurrency->code,
            businessDate: $businessDate,
            multiplier: $conversion['multiplier'] ?? null,
            direction: $conversion['direction'] ?? null,
            pairCode: $conversion['pair_code'] ?? null,
            source: $conversion['source'] ?? null,
            requiresManual: $conversion === null,
        );
    }

    public function retryPendingForTenant(int $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $active = $this->repository->activeForTenant($tenantId, true);
            if ($active !== null && in_array($active->status, ['waiting_for_rates', 'failed'], true)) {
                $this->updateStatus($active, ['status' => 'queued', 'queued_at' => now()]);
                RecalculateReportingCurrencyJob::dispatch($active->id)->afterCommit();
            }
        });
    }

    public function process(int $recalculationId): void
    {
        $recalculation = DB::transaction(function () use ($recalculationId): ?ReportingCurrencyRecalculation {
            $locked = $this->repository->find($recalculationId, true);
            if ($locked === null || ! in_array($locked->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
                return null;
            }

            return $this->updateStatus($locked, [
                'status' => 'processing',
                'started_at' => now(),
                'attempt_count' => $locked->attempt_count + 1,
                'error_message' => null,
            ]);
        });

        if ($recalculation === null) return;

        $missingRates = $this->missingRates($recalculation, false);
        if ($missingRates !== []) {
            DB::transaction(function () use ($recalculation, $missingRates): void {
                $locked = $this->repository->find($recalculation->id, true);
                if ($locked !== null && in_array($locked->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
                    $this->updateStatus($locked, ['status' => 'waiting_for_rates', 'missing_rates' => $missingRates]);
                }
            });

            return;
        }

        DB::transaction(function () use ($recalculation): void {
            $locked = $this->repository->find($recalculation->id, true);
            if ($locked === null || ! in_array($locked->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
                return;
            }
            $this->repository->lockCurrencyPreferences($recalculation->tenant_id);

            $transactions = $this->repository->affectedTransactions($locked, true);
            $conversions = [];
            $missingRates = [];

            foreach ($transactions as $transaction) {
                $date = $transaction->business_date?->toDateString() ?? $transaction->occurred_at->toDateString();
                $conversion = $this->exchangeRates->conversion(
                    $locked->tenant_id,
                    (int) $transaction->currency_id,
                    (int) $locked->requested_reporting_currency_id,
                    $date,
                );

                if ($conversion === null) {
                    $missingRates[$date.':'.$transaction->currency_id] = $this->missingRate($transaction->currency_id, $locked->requested_reporting_currency_id, $date);
                } else {
                    $conversions[$transaction->id] = $conversion;
                }
            }

            if ($missingRates !== []) {
                $this->updateStatus($locked, [
                    'status' => 'waiting_for_rates',
                    'missing_rates' => array_values($missingRates),
                ]);

                return;
            }

            foreach ($transactions as $transaction) {
                $conversion = $conversions[$transaction->id];
                $this->repository->updateTransaction(
                    $transaction,
                    (float) $transaction->amount * $conversion['multiplier'],
                    $conversion['multiplier'],
                );
            }

            $this->rebuildCompletedMonths($locked);
            $this->updateStatus($locked, [
                'status' => 'completed',
                'missing_rates' => null,
                'completed_at' => now(),
            ]);
        });

        $this->flushFinancialCaches($recalculation->tenant_id);
    }

    public function markFailed(int $recalculationId, string $message): void
    {
        DB::transaction(function () use ($recalculationId, $message): void {
            $recalculation = $this->repository->find($recalculationId, true);
            if ($recalculation !== null && in_array($recalculation->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
                $this->updateStatus($recalculation, [
                    'status' => 'failed',
                    'error_message' => mb_substr($message, 0, 2000),
                ]);
            }
        });
    }

    public function abort(int $tenantId, int $recalculationId, int $updateKey): TenantSetting
    {
        return DB::transaction(function () use ($tenantId, $recalculationId, $updateKey): TenantSetting {
            $recalculation = $this->repository->findForTenant($recalculationId, $tenantId, true);
            if ($recalculation === null || ! in_array($recalculation->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
                throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceHistoricalRateBackfillUnavailable));
            }

            $setting = $this->repository->lockCurrencyPreferences($tenantId);
            if ((int) $setting->update_key !== $updateKey) {
                throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
            }

            $setting = $this->repository->updateCurrencyPreferences($setting, [
                'reporting_currency_id' => $recalculation->previous_reporting_currency_id,
                'update_key' => $setting->update_key + 1,
            ]);
            $this->updateStatus($recalculation, [
                'status' => 'cancelled',
                'missing_rates' => null,
                'cancelled_at' => now(),
            ]);

            return $setting;
        });
    }

    public function requeueAfterHistoricalRates(ReportingCurrencyRecalculation $recalculation): ReportingCurrencyRecalculation
    {
        $recalculation = $this->updateStatus($recalculation, [
            'status' => 'queued',
            'missing_rates' => null,
            'queued_at' => now(),
        ]);
        RecalculateReportingCurrencyJob::dispatch($recalculation->id)->afterCommit();

        return $recalculation;
    }

    private function missingRates(ReportingCurrencyRecalculation $recalculation, bool $lock): array
    {
        $missing = [];
        foreach ($this->repository->affectedTransactions($recalculation, $lock) as $transaction) {
            $date = $transaction->business_date?->toDateString() ?? $transaction->occurred_at->toDateString();
            if ($this->exchangeRates->conversion(
                $recalculation->tenant_id,
                (int) $transaction->currency_id,
                (int) $recalculation->requested_reporting_currency_id,
                $date,
            ) === null) {
                $missing[$date.':'.$transaction->currency_id] = $this->missingRate(
                    $transaction->currency_id,
                    $recalculation->requested_reporting_currency_id,
                    $date,
                );
            }
        }

        return array_values($missing);
    }

    private function missingRate(int $fromCurrencyId, int $toCurrencyId, string $date): array
    {
        return [
            'date' => $date,
            'from_currency_id' => $fromCurrencyId,
            'to_currency_id' => $toCurrencyId,
        ];
    }

    private function rebuildCompletedMonths(ReportingCurrencyRecalculation $recalculation): void
    {
        $month = CarbonImmutable::parse($recalculation->window_start)->startOfMonth();
        $currentMonth = CarbonImmutable::parse($recalculation->window_end)->startOfMonth();

        while ($month->isBefore($currentMonth)) {
            $this->accountingSummaries->summarize(
                $recalculation->tenant_id,
                $month,
                (int) $recalculation->requested_reporting_currency_id,
            );
            $this->accountSummaries->summarize($recalculation->tenant_id, $month);
            $month = $month->addMonth();
        }
    }

    private function flushFinancialCaches(int $tenantId): void
    {
        foreach ([
            'tenant-accounting-transaction-list',
            'tenant-accounting-transaction-incoming-list',
            'tenant-accounting-transaction-outgoing-list',
            'tenant-accounting-transaction-overview',
        ] as $namespace) {
            $this->tenantScopedCacheKeys->bumpVersion($namespace, tenantId: $tenantId);
        }
    }

    private function updateStatus(ReportingCurrencyRecalculation $recalculation, array $data): ReportingCurrencyRecalculation
    {
        $previousStatus = $recalculation->status;
        $updated = $this->repository->update($recalculation, $data);

        if (array_key_exists('status', $data) && $updated->status !== $previousStatus) {
            $this->notificationService->recordReportingCurrencyStatus($updated);
        }

        return $updated;
    }
}
