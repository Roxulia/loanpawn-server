<?php

namespace App\Services\PlatformModule;

use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\ExchangeRatePairRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminExchangeRatePairService
{
    public function __construct(private ExchangeRatePairRepository $pairs) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->pairs->platform($perPage);
    }

    public function create(array $data): ExchangeRatePair
    {
        [$base, $quote] = $this->currencies($data);
        $code = "{$base->code}-{$quote->code}";
        if ($this->pairs->findOwned($code, null)) {
            throw new InvalidTenantRequest('This default exchange pair already exists.');
        }

        return $this->pairs->create(['tenant_id' => null, 'scope_key' => 'platform', 'code' => $code, 'base_currency_id' => $base->id, 'quote_currency_id' => $quote->id, 'is_default' => true, 'is_active' => true, 'created_by_platform_admin_id' => Auth::guard('platformadmin')->id()]);
    }

    public function update(ExchangeRatePair $pair, array $data): ExchangeRatePair
    {
        if ($pair->tenant_id !== null) {
            throw new InvalidTenantRequest('Only default pairs are managed here.');
        } if ((int) $data['update_key'] !== $pair->update_key) {
            throw new InvalidTenantRequest('This exchange pair was already updated. Refresh and try again.');
        } [$base, $quote] = $this->currencies($data);
        $changingDirection = $base->id !== $pair->base_currency_id || $quote->id !== $pair->quote_currency_id;
        if ($changingDirection && $pair->entries()->exists()) {
            throw new InvalidTenantRequest('Pair direction cannot change after a rate has been entered.');
        } if ($changingDirection) {
            $existing = $this->pairs->findOwned("{$base->code}-{$quote->code}", null);
            if ($existing && $existing->id !== $pair->id) {
                throw new InvalidTenantRequest('This default exchange pair already exists.');
            } $pair->base_currency_id = $base->id;
            $pair->quote_currency_id = $quote->id;
            $pair->code = "{$base->code}-{$quote->code}";
        } $pair->is_active = $data['is_active'] ?? $pair->is_active;
        $pair->update_key++;
        $pair->save();

        return $pair->refresh()->load(['baseCurrency', 'quoteCurrency']);
    }

    public function delete(ExchangeRatePair $pair): void
    {
        if ($pair->tenant_id !== null) {
            throw new InvalidTenantRequest('Only default pairs are managed here.');
        } $pair->delete();
    }

    private function currencies(array $data): array
    {
        $base = Currency::query()->whereNull('tenant_id')->where('code', strtoupper($data['base_currency_code']))->where('is_active', true)->first();
        $quote = Currency::query()->whereNull('tenant_id')->where('code', strtoupper($data['quote_currency_code']))->where('is_active', true)->first();
        if (! $base || ! $quote || $base->id === $quote->id) {
            throw new InvalidTenantRequest('Choose two different active default currencies.');
        }

        return [$base, $quote];
    }
}
