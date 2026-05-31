<?php

namespace App\Repository;

use App\Models\CoreModule\TenantDebt;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class TenantDebtRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantDebt::query()
            ->with('slip')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantDebt
    {
        $this->requireValue($data, 'code');

        return TenantDebt::query()
            ->create($data)
            ->load('slip');
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

        return $debt->refresh()->load('slip');
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
            ->with('slip')
            ->find($debtId);
    }

    public function findByCode(string $code): ?TenantDebt
    {
        return TenantDebt::query()
            ->with('slip')
            ->where('code', $code)
            ->first();
    }

    public function findByIdWithLock(int $debtId): ?TenantDebt
    {
        return TenantDebt::query()
            ->with('slip')
            ->whereKey($debtId)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?TenantDebt
    {
        return TenantDebt::query()
            ->with('slip')
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
            ->with('slip')
            ->where('slip_id', $slipId)
            ->orderBy('id')
            ->get();
    }

    public function findBySlipIdWithLock(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with('slip')
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
            ->with('slip')
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->orderBy('id')
            ->get();
    }

    public function findUnpaidBySlipIdWithLock(int $slipId): Collection
    {
        return TenantDebt::query()
            ->with('slip')
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function totalUnpaidForSlip(int $slipId): float
    {
        return (float) TenantDebt::query()
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->sum('amount');
    }
}
