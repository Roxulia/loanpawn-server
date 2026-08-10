<?php

namespace App\Services\PlatformModule;

use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Repository\CurrencyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminCurrencyService
{
    public function __construct(private CurrencyRepository $repository) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->platform($perPage);
    }

    public function create(array $data): Currency
    {
        $code = strtoupper(trim($data['code']));
        if ($this->repository->findOwned($code, null)) {
            throw new InvalidTenantRequest('This default currency code already exists.');
        }

        return $this->repository->create($this->payload($data, $code) + ['created_by_platform_admin_id' => Auth::guard('platformadmin')->id()]);
    }

    public function update(Currency $currency, array $data): Currency
    {
        if ($currency->tenant_id !== null) {
            throw new InvalidTenantRequest('Admin currency management only accepts default currencies.');
        }
        if ((int) $data['update_key'] !== $currency->update_key) {
            throw new InvalidTenantRequest('This currency was already updated. Refresh and try again.');
        }
        $nextCode = strtoupper(trim($data['code'] ?? $currency->code));
        $existing = $this->repository->findOwned($nextCode, null);
        if ($existing && $existing->id !== $currency->id) {
            throw new InvalidTenantRequest('This default currency code already exists.');
        }
        if ($nextCode !== $currency->code && ($currency->basePairs()->exists() || $currency->quotePairs()->exists())) {
            throw new InvalidTenantRequest('A currency code cannot change after it is used by an exchange pair.');
        }
        $currency->update($this->payload($data, $nextCode) + ['update_key' => $currency->update_key + 1]);

        return $currency->refresh();
    }

    public function delete(Currency $currency): void
    {
        if ($currency->tenant_id !== null) {
            throw new InvalidTenantRequest('Only default currencies are managed here.');
        }
        if ($currency->basePairs()->exists() || $currency->quotePairs()->exists()) {
            throw new InvalidTenantRequest('Delete exchange pairs that use this currency first.');
        }
        $currency->delete();
    }

    private function payload(array $data, string $code): array
    {
        return ['tenant_id' => null, 'scope_key' => 'platform', 'code' => $code, 'name' => trim($data['name']), 'symbol' => $data['symbol'] ?? null, 'decimal_precision' => $data['decimal_precision'] ?? 2, 'rounding_mode' => $data['rounding_mode'] ?? 'HALF_UP', 'adjustment_step' => $data['adjustment_step'] ?? null, 'is_default' => true, 'is_active' => $data['is_active'] ?? true];
    }
}
