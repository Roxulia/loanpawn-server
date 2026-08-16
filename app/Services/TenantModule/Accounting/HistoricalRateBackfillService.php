<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\HistoricalRateBackfillRequest;
use App\DataObjects\ResponseObjects\HistoricalRateRequirementsResource;
use App\Exceptions\InvalidTenantRequest;
use App\Jobs\RecalculateReportingCurrencyJob;
use App\Models\ReportingCurrencyRecalculation;
use App\Repository\Accounting\ReportingCurrencyRecalculationRepository;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Services\ExchangeRate\ExchangeRateSummaryService;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Services\TenantModule\TenantCurrencyService;
use App\Support\TenantContext;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HistoricalRateBackfillService
{
    public function __construct(
        private ReportingCurrencyRecalculationRepository $repository,
        private ReportingExchangeRateService $exchangeRates,
        private ExchangeRateEntryWriter $writer,
        private ExchangeRateSummaryService $summaries,
        private TenantCurrencyService $currencies,
        private TenantContext $tenantContext,
        private Messages $messages,
    ) {}

    public function requirements(): HistoricalRateRequirementsResource
    {
        $tenantId = $this->tenantId();
        $recalculation = $this->repository->activeForTenant($tenantId);

        return $this->resource($this->assertEligible($recalculation), $tenantId);
    }

    public function submit(HistoricalRateBackfillRequest $request): HistoricalRateRequirementsResource
    {
        $tenantId = $this->tenantId();

        return DB::transaction(function () use ($request, $tenantId): HistoricalRateRequirementsResource {
            $recalculation = $this->assertEligible(
                $this->repository->findForTenant($request->recalculationId, $tenantId, true),
            );
            $requirements = $this->unresolvedRequirements($recalculation, $tenantId);
            $expectedKeys = array_column($requirements, 'requirement_key');
            $submittedKeys = array_column($request->rates, 'requirement_key');
            sort($expectedKeys);
            sort($submittedKeys);

            if ($expectedKeys === [] || $expectedKeys !== $submittedKeys || count($submittedKeys) !== count(array_unique($submittedKeys))) {
                throw new InvalidTenantRequest($this->message(MessageCode::FinanceHistoricalRateBackfillMismatch));
            }
            if (collect($requirements)->contains(fn (array $item): bool => $item['pair'] === null)) {
                throw new InvalidTenantRequest($this->message(MessageCode::FinanceHistoricalRatePairRequired));
            }

            $submitted = collect($request->rates)->keyBy('requirement_key');
            $tenantUserId = Auth::guard('tenantuser')->id();
            foreach ($requirements as $requirement) {
                $values = $submitted->get($requirement['requirement_key']);
                $pair = $requirement['_pair'];
                $idempotencyPrefix = "reporting-recalc:{$recalculation->id}:{$requirement['requirement_key']}";
                $this->writer->create($pair, [
                    'buying_rate' => $values['buying_open'],
                    'selling_rate' => $values['selling_open'],
                    'effective_date' => $requirement['date'],
                    'idempotency_key' => "{$idempotencyPrefix}:open",
                ], $tenantId, $tenantUserId, null);
                $this->writer->create($pair, [
                    'buying_rate' => $values['buying_close'],
                    'selling_rate' => $values['selling_close'],
                    'effective_date' => $requirement['date'],
                    'idempotency_key' => "{$idempotencyPrefix}:close",
                ], $tenantId, $tenantUserId, null);
                $this->summaries->rebuild("tenant:{$tenantId}", $tenantId, $pair->id, $requirement['date']);
            }

            $recalculation = $this->repository->update($recalculation, [
                'status' => 'queued',
                'missing_rates' => null,
                'queued_at' => now(),
            ]);
            RecalculateReportingCurrencyJob::dispatch($recalculation->id)->afterCommit();

            return $this->resource($recalculation, $tenantId, []);
        });
    }

    private function resource(ReportingCurrencyRecalculation $recalculation, int $tenantId, ?array $requirements = null): HistoricalRateRequirementsResource
    {
        $setting = $this->repository->currencyPreferences($tenantId);

        return new HistoricalRateRequirementsResource(
            recalculationId: $recalculation->id,
            status: $recalculation->status,
            previousCurrency: $this->currencyArray($recalculation->previousReportingCurrency),
            requestedCurrency: $this->currencyArray($recalculation->requestedReportingCurrency),
            requirements: $requirements ?? $this->publicRequirements($this->unresolvedRequirements($recalculation, $tenantId)),
            currencySettingUpdateKey: (int) $setting->update_key,
        );
    }

    private function unresolvedRequirements(ReportingCurrencyRecalculation $recalculation, int $tenantId): array
    {
        $requirements = [];
        foreach ($recalculation->missing_rates ?? [] as $missing) {
            $date = (string) $missing['date'];
            $fromId = (int) $missing['from_currency_id'];
            $toId = (int) $missing['to_currency_id'];
            if ($this->exchangeRates->conversion($tenantId, $fromId, $toId, $date) !== null) continue;

            $candidate = $this->exchangeRates->pairForConversion($tenantId, $fromId, $toId);
            $from = $this->currencies->findActiveVisibleForTenant($tenantId, $fromId);
            $to = $this->currencies->findActiveVisibleForTenant($tenantId, $toId);
            $key = hash('sha256', "{$recalculation->id}|{$date}|{$fromId}|{$toId}");
            $requirements[] = [
                'requirement_key' => $key,
                'date' => $date,
                'from_currency' => $this->currencyArray($from),
                'to_currency' => $this->currencyArray($to),
                'pair' => $candidate === null ? null : [
                    'code' => $candidate['pair']->code,
                    'display_code' => "{$candidate['pair']->baseCurrency->code}/{$candidate['pair']->quoteCurrency->code}",
                    'direction' => $candidate['direction'],
                ],
                '_pair' => $candidate['pair'] ?? null,
            ];
        }

        return $requirements;
    }

    private function publicRequirements(array $requirements): array
    {
        return array_map(function (array $item): array {
            unset($item['_pair']);

            return $item;
        }, $requirements);
    }

    private function assertEligible(?ReportingCurrencyRecalculation $recalculation): ReportingCurrencyRecalculation
    {
        if ($recalculation === null
            || ! in_array($recalculation->status, ['waiting_for_rates', 'failed'], true)
            || empty($recalculation->missing_rates)) {
            throw new InvalidTenantRequest($this->message(MessageCode::FinanceHistoricalRateBackfillUnavailable));
        }

        return $recalculation;
    }

    private function tenantId(): int
    {
        return $this->tenantContext->id()
            ?? throw new InvalidTenantRequest($this->message(MessageCode::FinanceHistoricalRateBackfillUnavailable));
    }

    private function currencyArray(object $currency): array
    {
        return ['id' => (int) $currency->id, 'code' => $currency->code, 'name' => $currency->name];
    }

    private function message(MessageCode $code): string
    {
        return $this->messages->responseMessage($code);
    }
}
