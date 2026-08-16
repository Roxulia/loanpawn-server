<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\CorrectExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\VoidExchangeRateRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\ExchangeRateEntry;
use App\DataObjects\ResponseObjects\ExchangeRateEntryResource;
use App\Repository\ExchangeRateEntryRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\BaseTenantService;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateActionPolicy;
use App\Services\ExchangeRate\ExchangeRateBusinessClock;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Services\ExchangeRate\ExchangeRateResolverService;
use App\Utility\MessageCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;

class TenantExchangeRateService extends BaseTenantService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRatePairRepository $pairs, private ExchangeRateEntryWriter $writer, private ExchangeRateCorrectionService $corrections, private ExchangeRateResolverService $resolver, private ExchangeRateActionPolicy $actions, private ExchangeRateBusinessClock $clock, private ReportingCurrencyRecalculationService $reportingCurrencyRecalculationService) {}

    public function list(int $perPage = 50, ?string $pairCode = null): LengthAwarePaginator
    {
        $page = $this->entries->visibleToTenant($this->resolveCurrentTenantId(), $perPage, $pairCode);
        $page->through(function (ExchangeRateEntry $entry): ExchangeRateEntry {
            $entry = $this->actions->apply($entry);
            if ($entry->effective_date->toDateString() !== $this->clock->now($entry->tenant_id)->toDateString()) {
                $entry->setAttribute('can_correct', false);
                $entry->setAttribute('can_void', false);
            }

            return $entry;
        });

        return $page;
    }

    public function show(string $code): ExchangeRateEntry
    {
        return $this->entries->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangeRateNotFound));
    }

    public function create(StoreExchangeRateRequest $request): ExchangeRateEntry
    {
        $tenantId = $this->resolveCurrentTenantId();
        $businessDate = $this->clock->now($tenantId)->toDateString();
        if ($request->effectiveDate !== null && $request->effectiveDate !== $businessDate) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangeRateActionWindowClosed));
        }
        $pair = $this->pairs->findVisible($request->pairCode, $tenantId);
        if (! $pair || ! $pair->is_active) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceActiveVisibleExchangePairRequired));
        }

        $entry = $this->writer->create($pair, $request->toArray(), $tenantId, Auth::guard('tenantuser')->id(), null);
        $this->reportingCurrencyRecalculationService->retryPendingForTenant($tenantId);

        return $entry;
    }

    public function correct(string $code, CorrectExchangeRateRequest $request): ExchangeRateEntry
    {
        $entry = $this->owned($code);

        $this->assertCurrentBusinessDay($entry);

        $this->actions->assertCorrectable($entry);

        return $this->corrections->correct($entry, $request->buyingRate, $request->sellingRate, $request->reason, Auth::guard('tenantuser')->id(), null);
    }

    public function void(string $code, VoidExchangeRateRequest $request): void
    {
        $entry = $this->owned($code);
        $this->assertCurrentBusinessDay($entry);
        $this->actions->assertVoidable($entry);
        $this->corrections->void($entry, $request->reason, Auth::guard('tenantuser')->id(), null);
    }

    public function state(string $pairCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->findVisible($pairCode, $tenantId) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairNotFound));
        $businessNow = $this->clock->now($tenantId);
        $latest = $this->entries->latestActiveForDay("tenant:{$tenantId}", $pair->id, $businessNow->toDateString());

        return [
            'business_date' => $businessNow->toDateString(),
            'timezone' => $businessNow->timezoneName,
            'opening_required' => $latest === null,
            'latest_entry' => $latest ? ExchangeRateEntryResource::fromModel($this->actions->apply($latest))->toArray() : null,
        ];
    }

    public function resolve(string $pairCode, string $date): ?ExchangeRateEntry
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->findVisible($pairCode, $tenantId) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairNotFound));

        return $this->resolver->resolve($pair, $tenantId, $date);
    }

    private function owned(string $code): ExchangeRateEntry
    {
        return $this->entries->findOwned($code, $this->resolveCurrentTenantId()) ?? throw new TenantAccessDenied($this->responseMessage(MessageCode::FinanceTenantExchangeRateModificationDenied));
    }

    private function assertCurrentBusinessDay(ExchangeRateEntry $entry): void
    {
        if ($entry->effective_date->toDateString() !== $this->clock->now($entry->tenant_id)->toDateString()) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangeRateActionWindowClosed));
        }
    }
}
