<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\StoreExchangeRatePairRequest;
use App\DataObjects\RequestObjects\UpdateExchangeRatePairRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\ExchangeRatePairRepository;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminExchangeRatePairService
{
    public function __construct(private ExchangeRatePairRepository $pairs, private Messages $messages) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->pairs->platform($perPage);
    }

    public function create(StoreExchangeRatePairRequest $request): ExchangeRatePair
    {
        [$base, $quote] = $this->currencies($request);
        $code = "{$base->code}-{$quote->code}";
        if ($this->pairs->findOwned($code, null)) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultExchangePairExists));
        }

        return $this->pairs->create(['tenant_id' => null, 'scope_key' => 'platform', 'code' => $code, 'base_currency_id' => $base->id, 'quote_currency_id' => $quote->id, 'is_default' => true, 'is_active' => true, 'created_by_platform_admin_id' => Auth::guard('platformadmin')->id()]);
    }

    public function update(ExchangeRatePair $pair, UpdateExchangeRatePairRequest $request): ExchangeRatePair
    {
        if ($pair->tenant_id !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultExchangePairRequired));
        } if ($request->updateKey !== $pair->update_key) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceExchangePairAlreadyUpdated));
        } [$base, $quote] = $this->currencies($request);
        $changingDirection = $base->id !== $pair->base_currency_id || $quote->id !== $pair->quote_currency_id;
        if ($changingDirection && $pair->entries()->exists()) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceExchangePairDirectionLocked));
        } if ($changingDirection) {
            $existing = $this->pairs->findOwned("{$base->code}-{$quote->code}", null);
            if ($existing && $existing->id !== $pair->id) {
                throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultExchangePairExists));
            } $pair->base_currency_id = $base->id;
            $pair->quote_currency_id = $quote->id;
            $pair->code = "{$base->code}-{$quote->code}";
        } $pair->is_active = $request->isActive ?? $pair->is_active;
        $pair->update_key++;
        $pair->save();

        return $pair->refresh()->load(['baseCurrency', 'quoteCurrency']);
    }

    public function delete(ExchangeRatePair $pair): void
    {
        if ($pair->tenant_id !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultExchangePairRequired));
        } $pair->delete();
    }

    private function currencies(StoreExchangeRatePairRequest|UpdateExchangeRatePairRequest $request): array
    {
        $base = Currency::query()->whereNull('tenant_id')->where('code', strtoupper($request->baseCurrencyCode))->where('is_active', true)->first();
        $quote = Currency::query()->whereNull('tenant_id')->where('code', strtoupper($request->quoteCurrencyCode))->where('is_active', true)->first();
        if (! $base || ! $quote || $base->id === $quote->id) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDistinctActiveDefaultCurrenciesRequired));
        }

        return [$base, $quote];
    }
}
