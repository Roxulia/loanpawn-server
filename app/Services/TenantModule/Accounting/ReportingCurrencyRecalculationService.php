<?php

namespace App\Services\TenantModule\Accounting;

use App\Exceptions\InvalidTenantRequest;
use App\Jobs\RecalculateReportingCurrencyJob;
use App\Models\ReportingCurrencyRecalculation;
use App\Repository\Accounting\ReportingCurrencyRecalculationRepository;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportingCurrencyRecalculationService
{
    public function __construct(
        private ReportingCurrencyRecalculationRepository $repository,
        private ReportingExchangeRateService $exchangeRates,
        private TenantAccountingMonthlySummaryService $accountingSummaries,
        private FinancialAccountMonthlySummaryService $accountSummaries,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {}

    public function start(int $tenantId, int $previousCurrencyId, int $requestedCurrencyId, string $businessDate): ?ReportingCurrencyRecalculation
    {
        if ($previousCurrencyId === $requestedCurrencyId) {
            return null;
        }

        if ($this->repository->activeForTenant($tenantId, true) !== null) {
            throw new InvalidTenantRequest('Finish the current reporting currency recalculation before changing it again.');
        }

        $currentDate = CarbonImmutable::parse($businessDate);
        $recalculation = $this->repository->create([
            'tenant_id' => $tenantId,
            'previous_reporting_currency_id' => $previousCurrencyId,
            'requested_reporting_currency_id' => $requestedCurrencyId,
            'window_start' => $currentDate->startOfMonth()->subMonthsNoOverflow(2)->toDateString(),
            'window_end' => $currentDate->toDateString(),
            'status' => 'queued',
            'missing_rates' => null,
            'queued_at' => now(),
        ]);

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

        if ($providedReportingAmount !== null) {
            return [$providedReportingAmount, $providedExchangeRate];
        }

        if ($providedExchangeRate !== null) {
            return [$amount * $providedExchangeRate, $providedExchangeRate];
        }

        $conversion = $this->exchangeRates->conversion(
            $tenantId,
            $transactionCurrencyId,
            $effectiveCurrencyId,
            $businessDate,
        );

        return $conversion === null
            ? [null, null]
            : [$amount * $conversion['multiplier'], $conversion['rate']];
    }

    public function retryPendingForTenant(int $tenantId): void
    {
        $active = $this->repository->activeForTenant($tenantId);
        if ($active !== null && in_array($active->status, ['waiting_for_rates', 'failed'], true)) {
            $this->repository->update($active, ['status' => 'queued', 'queued_at' => now()]);
            RecalculateReportingCurrencyJob::dispatch($active->id)->afterCommit();
        }
    }

    public function process(int $recalculationId): void
    {
        $recalculation = $this->repository->find($recalculationId);
        if ($recalculation === null || ! in_array($recalculation->status, ReportingCurrencyRecalculation::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->repository->update($recalculation, [
            'status' => 'processing',
            'started_at' => now(),
            'attempt_count' => $recalculation->attempt_count + 1,
            'error_message' => null,
        ]);

        $missingRates = $this->missingRates($recalculation, false);
        if ($missingRates !== []) {
            $this->repository->update($recalculation, ['status' => 'waiting_for_rates', 'missing_rates' => $missingRates]);

            return;
        }

        DB::transaction(function () use ($recalculation): void {
            $this->repository->lockCurrencyPreferences($recalculation->tenant_id);
            $locked = $this->repository->find($recalculation->id, true);
            if ($locked === null) {
                return;
            }

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
                $this->repository->update($locked, [
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
                    $conversion['rate'],
                );
            }

            $this->rebuildCompletedMonths($locked);
            $this->repository->update($locked, [
                'status' => 'completed',
                'missing_rates' => null,
                'completed_at' => now(),
            ]);
        });

        $this->flushFinancialCaches($recalculation->tenant_id);
    }

    public function markFailed(int $recalculationId, string $message): void
    {
        $recalculation = $this->repository->find($recalculationId);
        if ($recalculation !== null && $recalculation->status !== 'completed') {
            $this->repository->update($recalculation, [
                'status' => 'failed',
                'error_message' => mb_substr($message, 0, 2000),
            ]);
        }
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
}
