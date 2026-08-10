<?php

namespace App\Services\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\BaseTenantService;
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
        return $this->pairs->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest('Exchange pair not found.');
    }

    public function create(array $data): ExchangeRatePair
    {
        $tenantId = $this->resolveCurrentTenantId();
        [$base, $quote] = $this->currencies($data, $tenantId);
        if ($this->pairs->directionExistsForTenant($tenantId, $base->id, $quote->id)) {
            throw new InvalidTenantRequest('This exchange pair is already available.');
        }

        return $this->pairs->create(['tenant_id' => $tenantId, 'scope_key' => "tenant:{$tenantId}", 'code' => "{$base->code}-{$quote->code}", 'base_currency_id' => $base->id, 'quote_currency_id' => $quote->id, 'is_default' => false, 'is_active' => true, 'created_by_tenant_user_id' => Auth::guard('tenantuser')->id()]);
    }

    public function update(string $code, array $data): ExchangeRatePair
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->owned($code, $tenantId);
        if ((int) $data['update_key'] !== $pair->update_key) {
            throw new InvalidTenantRequest('This exchange pair was already updated. Refresh and try again.');
        }
        [$base, $quote] = $this->currencies($data, $tenantId);
        $changingDirection = $base->id !== $pair->base_currency_id || $quote->id !== $pair->quote_currency_id;
        if ($changingDirection && $pair->entries()->exists()) {
            throw new InvalidTenantRequest('Pair direction cannot change after a rate has been entered.');
        }
        if ($changingDirection) {
            if ($this->pairs->directionExistsForTenant($tenantId, $base->id, $quote->id, $pair->id)) {
                throw new InvalidTenantRequest('This exchange pair is already available.');
            } $pair->base_currency_id = $base->id;
            $pair->quote_currency_id = $quote->id;
            $pair->code = "{$base->code}-{$quote->code}";
        }
        $pair->is_active = $data['is_active'] ?? $pair->is_active;
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
        return $this->pairs->findOwned($code, $tenantId) ?? throw new TenantAccessDenied('Only tenant-created exchange pairs can be changed.');
    }

    private function currencies(array $data, int $tenantId): array
    {
        $base = $this->currencies->findVisible($data['base_currency_code'], $tenantId);
        $quote = $this->currencies->findVisible($data['quote_currency_code'], $tenantId);
        if (! $base || ! $quote || ! $base->is_active || ! $quote->is_active) {
            throw new InvalidTenantRequest('Both currencies must be active and visible to this tenant.');
        }
        if ($base->id === $quote->id) {
            throw new InvalidTenantRequest('Base and quote currencies must be different.');
        }

        return [$base, $quote];
    }
}
