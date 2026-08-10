<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\StoreExchangeRatePairRequest;
use App\DataObjects\RequestObjects\UpdateExchangeRatePairRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\BaseTenantService;
use App\Utility\MessageCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class TenantExchangeRatePairService extends BaseTenantService
{
    public function __construct(private ExchangeRatePairRepository $pairs, private CurrencyRepository $currencies) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->pairs->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }

    public function show(string $code): ExchangeRatePair
    {
        return $this->pairs->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairNotFound));
    }

    public function create(StoreExchangeRatePairRequest $request): ExchangeRatePair
    {
        $tenantId = $this->resolveCurrentTenantId();
        [$base, $quote] = $this->currencies($request, $tenantId);
        if ($this->pairs->directionExistsForTenant($tenantId, $base->id, $quote->id)) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairAlreadyAvailable));
        }

        return $this->pairs->create(['tenant_id' => $tenantId, 'scope_key' => "tenant:{$tenantId}", 'code' => "{$base->code}-{$quote->code}", 'base_currency_id' => $base->id, 'quote_currency_id' => $quote->id, 'is_default' => false, 'is_active' => true, 'created_by_tenant_user_id' => Auth::guard('tenantuser')->id()]);
    }

    public function update(string $code, UpdateExchangeRatePairRequest $request): ExchangeRatePair
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->owned($code, $tenantId);
        if ($request->updateKey !== $pair->update_key) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairAlreadyUpdated));
        }
        [$base, $quote] = $this->currencies($request, $tenantId);
        $changingDirection = $base->id !== $pair->base_currency_id || $quote->id !== $pair->quote_currency_id;
        if ($changingDirection && $pair->entries()->exists()) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairDirectionLocked));
        }
        if ($changingDirection) {
            if ($this->pairs->directionExistsForTenant($tenantId, $base->id, $quote->id, $pair->id)) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceExchangePairAlreadyAvailable));
            } $pair->base_currency_id = $base->id;
            $pair->quote_currency_id = $quote->id;
            $pair->code = "{$base->code}-{$quote->code}";
        }
        $pair->is_active = $request->isActive ?? $pair->is_active;
        $pair->update_key++;
        $pair->save();

        return $pair->refresh()->load(['baseCurrency', 'quoteCurrency']);
    }

    public function delete(string $code): void
    {
        $this->owned($code, $this->resolveCurrentTenantId())->delete();
    }

    private function owned(string $code, int $tenantId): ExchangeRatePair
    {
        return $this->pairs->findOwned($code, $tenantId) ?? throw new TenantAccessDenied($this->responseMessage(MessageCode::FinanceTenantExchangePairModificationDenied));
    }

    private function currencies(StoreExchangeRatePairRequest|UpdateExchangeRatePairRequest $request, int $tenantId): array
    {
        $base = $this->currencies->findVisible($request->baseCurrencyCode, $tenantId);
        $quote = $this->currencies->findVisible($request->quoteCurrencyCode, $tenantId);
        if (! $base || ! $quote || ! $base->is_active || ! $quote->is_active) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceVisibleActiveCurrenciesRequired));
        }
        if ($base->id === $quote->id) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceDistinctPairCurrenciesRequired));
        }

        return [$base, $quote];
    }
}
