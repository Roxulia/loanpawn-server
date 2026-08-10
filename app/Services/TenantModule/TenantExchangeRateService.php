<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\CorrectExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\VoidExchangeRateRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateEntryRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\BaseTenantService;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Services\ExchangeRate\ExchangeRateResolverService;
use App\Utility\MessageCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class TenantExchangeRateService extends BaseTenantService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRatePairRepository $pairs, private ExchangeRateEntryWriter $writer, private ExchangeRateCorrectionService $corrections, private ExchangeRateResolverService $resolver) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->entries->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }

    public function show(string $code): ExchangeRateEntry
    {
        return $this->entries->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangeRateNotFound));
    }

    public function create(StoreExchangeRateRequest $request): ExchangeRateEntry
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->findVisible($request->pairCode, $tenantId);
        if (! $pair || ! $pair->is_active) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceActiveVisibleExchangePairRequired));
        }

        return $this->writer->create($pair, $request->toArray(), $tenantId, Auth::guard('tenantuser')->id(), null);
    }

    public function correct(string $code, CorrectExchangeRateRequest $request): ExchangeRateEntry
    {
        $entry = $this->owned($code);

        return $this->corrections->correct($entry, $request->rate, $request->reason, Auth::guard('tenantuser')->id(), null);
    }

    public function void(string $code, VoidExchangeRateRequest $request): void
    {
        $this->corrections->void($this->owned($code), $request->reason, Auth::guard('tenantuser')->id(), null);
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
}
