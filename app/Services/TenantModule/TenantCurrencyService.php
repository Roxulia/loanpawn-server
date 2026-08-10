<?php

namespace App\Services\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\Currency;
use App\Repository\CurrencyRepository;
use App\Services\BaseTenantService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class TenantCurrencyService extends BaseTenantService
{
    public function __construct(private CurrencyRepository $repository) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }

    public function show(string $code): Currency
    {
        return $this->repository->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest('Currency not found.');
    }

    public function create(array $data): Currency
    {
        $tenantId = $this->resolveCurrentTenantId();
        $code = strtoupper(trim($data['code']));
        if ($this->repository->findVisible($code, $tenantId)) {
            throw new InvalidTenantRequest('This currency code is already available.');
        }

        return $this->repository->create($this->payload($data, $tenantId, $code) + ['created_by_tenant_user_id' => Auth::guard('tenantuser')->id()]);
    }

    public function update(string $code, array $data): Currency
    {
        $tenantId = $this->resolveCurrentTenantId();
        $currency = $this->owned($code, $tenantId);
        if ((int) $data['update_key'] !== $currency->update_key) {
            throw new InvalidTenantRequest('This currency was already updated. Refresh and try again.');
        }
        $nextCode = strtoupper(trim($data['code'] ?? $currency->code));
        if ($nextCode !== $currency->code && ($currency->basePairs()->exists() || $currency->quotePairs()->exists())) {
            throw new InvalidTenantRequest('A currency code cannot change after it is used by an exchange pair.');
        }
        if ($nextCode !== $currency->code && $this->repository->findVisible($nextCode, $tenantId)) {
            throw new InvalidTenantRequest('This currency code is already available.');
        }
        $currency->update($this->payload($data, $tenantId, $nextCode) + ['update_key' => $currency->update_key + 1]);

        return $currency->refresh();
    }

    public function delete(string $code): void
    {
        $currency = $this->owned($code, $this->resolveCurrentTenantId());
        if ($currency->basePairs()->exists() || $currency->quotePairs()->exists()) {
            throw new InvalidTenantRequest('Delete exchange pairs that use this currency first.');
        }
        $currency->delete();
    }

    private function owned(string $code, int $tenantId): Currency
    {
        $currency = $this->repository->findOwned($code, $tenantId);
        if (! $currency) {
            throw new TenantAccessDenied('Only tenant-created currencies can be changed.');
        }

        return $currency;
    }

    private function payload(array $data, int $tenantId, string $code): array
    {
        return ['tenant_id' => $tenantId, 'scope_key' => "tenant:{$tenantId}", 'code' => $code, 'name' => trim($data['name']), 'symbol' => $data['symbol'] ?? null, 'decimal_precision' => $data['decimal_precision'] ?? 2, 'rounding_mode' => $data['rounding_mode'] ?? 'HALF_UP', 'adjustment_step' => $data['adjustment_step'] ?? null, 'is_default' => false, 'is_active' => $data['is_active'] ?? true];
    }
}
