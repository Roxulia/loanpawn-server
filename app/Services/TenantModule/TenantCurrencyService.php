<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\UpdateCurrencyRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\Currency;
use App\Repository\CurrencyRepository;
use App\Services\BaseTenantService;
use App\Utility\MessageCode;
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
        return $this->repository->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyNotFound));
    }

    public function create(StoreCurrencyRequest $request): Currency
    {
        $tenantId = $this->resolveCurrentTenantId();
        $code = strtoupper(trim($request->code));
        if ($this->repository->findVisible($code, $tenantId)) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyCodeAlreadyAvailable));
        }

        return $this->repository->create($this->payload($request, $tenantId, $code) + ['created_by_tenant_user_id' => Auth::guard('tenantuser')->id()]);
    }

    public function update(string $code, UpdateCurrencyRequest $request): Currency
    {
        $tenantId = $this->resolveCurrentTenantId();
        $currency = $this->owned($code, $tenantId);
        if ($request->updateKey !== $currency->update_key) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyAlreadyUpdated));
        }
        $nextCode = strtoupper(trim($request->code));
        if ($nextCode !== $currency->code && ($currency->basePairs()->exists() || $currency->quotePairs()->exists())) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyCodeLockedByPair));
        }
        if ($nextCode !== $currency->code && $this->repository->findVisible($nextCode, $tenantId)) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyCodeAlreadyAvailable));
        }
        $currency->update($this->payload($request, $tenantId, $nextCode) + ['update_key' => $currency->update_key + 1]);

        return $currency->refresh();
    }

    public function delete(string $code): void
    {
        $currency = $this->owned($code, $this->resolveCurrentTenantId());
        if ($currency->basePairs()->exists() || $currency->quotePairs()->exists()) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceCurrencyUsedByPair));
        }
        $currency->delete();
    }

    private function owned(string $code, int $tenantId): Currency
    {
        $currency = $this->repository->findOwned($code, $tenantId);
        if (! $currency) {
            throw new TenantAccessDenied($this->responseMessage(MessageCode::FinanceTenantCurrencyModificationDenied));
        }

        return $currency;
    }

    private function payload(StoreCurrencyRequest|UpdateCurrencyRequest $request, int $tenantId, string $code): array
    {
        return ['tenant_id' => $tenantId, 'scope_key' => "tenant:{$tenantId}", 'code' => $code, 'name' => trim($request->name), 'symbol' => $request->symbol, 'decimal_precision' => $request->decimalPrecision, 'rounding_mode' => $request->roundingMode, 'adjustment_step' => $request->adjustmentStep, 'is_default' => false, 'is_active' => $request->isActive ?? true];
    }
}
