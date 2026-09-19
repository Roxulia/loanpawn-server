<?php

namespace App\Repository;

use App\Exceptions\RequiredValueMissing;
use App\Models\CoreModule\TenantDebt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TenantDebtRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'createdAccount.currency', 'acceptAccount.currency'])
            ->withSum('interestAccruals as total_interest_accrued', 'calculated_interest')
            ->withSum('interestAccruals as total_interest_paid', 'paid_amount')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantDebt
    {
        $this->requireValue($data, 'code');

        return TenantDebt::query()
            ->create($data)
            ->load(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency']);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant debt {$key} is required.");
        }
    }

    public function update(TenantDebt $debt, array $data): TenantDebt
    {
        $debt->update($data);

        return $debt->refresh()->load(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency']);
    }

    public function updateWithLock(TenantDebt $debt, array $data): TenantDebt
    {
        $lockedDebt = TenantDebt::query()
            ->whereKey($debt->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedDebt, $data);
    }

    public function delete(TenantDebt $debt): void
    {
        $debt->delete();
    }

    public function findById(int $debtId): ?TenantDebt
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->find($debtId);
    }

    public function findByCode(string $code): ?TenantDebt
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('code', $code)
            ->first();
    }

    public function findByIdWithLock(int $debtId): ?TenantDebt
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->whereKey($debtId)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?TenantDebt
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('code', $code)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function findBySlipId(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->orderBy('id')
            ->get();
    }

    public function findBySlipIdWithLock(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, TenantDebt>
     */
    public function findUnpaidBySlipId(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->orderBy('id')
            ->get();
    }

    public function findUnpaidBySlipIdWithLock(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function findUnpaidBySlipIdAndCurrency(int $slipId, int $currencyId): Collection
    {
        return $this->redemptionDebtQuery($slipId)
            ->whereHas('createdAccount', fn ($query) => $query->where('currency_id', $currencyId))
            ->get();
    }

    public function findUnpaidBySlipIdAndCurrencyWithLock(int $slipId, int $currencyId): Collection
    {
        return $this->redemptionDebtQuery($slipId)
            ->whereHas('createdAccount', fn ($query) => $query->where('currency_id', $currencyId))
            ->lockForUpdate()
            ->get();
    }

    public function findUnpaidBySlipIdExceptCurrency(int $slipId, int $currencyId): Collection
    {
        return $this->redemptionDebtQuery($slipId)
            ->where(function ($query) use ($currencyId): void {
                $query->whereNull('created_account_id')
                    ->orWhereHas('createdAccount', fn ($accountQuery) => $accountQuery->where('currency_id', '!=', $currencyId));
            })
            ->get();
    }

    public function totalUnpaidForSlip(int $slipId): float
    {
        return (float) TenantDebt::query()
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->sum('principal_balance');
    }

    private function redemptionDebtQuery(int $slipId): Builder
    {
        return TenantDebt::query()
            ->with(['slip', 'customer', 'interestType', 'interestAccruals', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->orderBy('id');
    }
}
