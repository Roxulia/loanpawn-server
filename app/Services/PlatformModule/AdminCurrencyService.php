<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\UpdateCurrencyRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Repository\CurrencyRepository;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminCurrencyService
{
    public function __construct(private CurrencyRepository $repository, private Messages $messages) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->platform($perPage);
    }

    public function create(StoreCurrencyRequest $request): Currency
    {
        $code = strtoupper(trim($request->code));
        if ($this->repository->findOwned($code, null)) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultCurrencyCodeExists));
        }

        return $this->repository->create($this->payload($request, $code) + ['created_by_platform_admin_id' => Auth::guard('platformadmin')->id()]);
    }

    public function update(Currency $currency, UpdateCurrencyRequest $request): Currency
    {
        if ($currency->tenant_id !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceAdminCurrencyRequiresDefault));
        }
        if ($request->updateKey !== $currency->update_key) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceCurrencyAlreadyUpdated));
        }
        $nextCode = strtoupper(trim($request->code));
        $existing = $this->repository->findOwned($nextCode, null);
        if ($existing && $existing->id !== $currency->id) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultCurrencyCodeExists));
        }
        if ($nextCode !== $currency->code && ($currency->basePairs()->exists() || $currency->quotePairs()->exists())) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceCurrencyCodeLockedByPair));
        }
        $currency->update($this->payload($request, $nextCode) + ['update_key' => $currency->update_key + 1]);

        return $currency->refresh();
    }

    public function delete(Currency $currency): void
    {
        if ($currency->tenant_id !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceDefaultCurrencyRequired));
        }
        if ($currency->basePairs()->exists() || $currency->quotePairs()->exists()) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceCurrencyUsedByPair));
        }
        $currency->delete();
    }

    private function payload(StoreCurrencyRequest|UpdateCurrencyRequest $request, string $code): array
    {
        return ['tenant_id' => null, 'scope_key' => 'platform', 'code' => $code, 'name' => trim($request->name), 'symbol' => $request->symbol, 'decimal_precision' => $request->decimalPrecision, 'rounding_mode' => $request->roundingMode, 'adjustment_step' => $request->adjustmentStep, 'is_default' => true, 'is_active' => $request->isActive ?? true];
    }
}
