<?php

namespace App\Repository;

use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantDebtInterestAccrual;
use App\Models\CoreModule\TenantDebtPayment;
use App\Models\CoreModule\TenantDebtPaymentAllocation;
use Illuminate\Database\Eloquent\Collection;

class TenantDebtInterestRepository
{
    public function findDebtById(int $debtId, bool $lock = false): ?TenantDebt
    {
        $query = TenantDebt::query()->with(['interestType', 'createdAccount.currency']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($debtId);
    }

    public function createAccrual(array $data): TenantDebtInterestAccrual
    {
        return TenantDebtInterestAccrual::query()->create($data);
    }

    /** @return Collection<int, TenantDebtInterestAccrual> */
    public function accruals(int $debtId, bool $lock = false): Collection
    {
        $query = TenantDebtInterestAccrual::query()->where('debt_id', $debtId)->orderBy('start_period_at')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function updateAccrual(TenantDebtInterestAccrual $accrual, array $data): TenantDebtInterestAccrual
    {
        $accrual->update($data);

        return $accrual->refresh();
    }

    public function updateDebt(TenantDebt $debt, array $data): TenantDebt
    {
        $debt->update($data);

        return $debt->refresh()->load(['interestType', 'createdAccount.currency']);
    }

    public function createPayment(array $data): TenantDebtPayment
    {
        return TenantDebtPayment::query()->create($data);
    }

    public function createAllocation(array $data): TenantDebtPaymentAllocation
    {
        return TenantDebtPaymentAllocation::query()->create($data);
    }

    public function paymentExists(int $debtId): bool
    {
        return TenantDebtPayment::query()->where('debt_id', $debtId)->exists();
    }

    public function paymentHistory(int $debtId): array
    {
        return TenantDebtPayment::query()
            ->with('acceptAccount.currency')
            ->where('debt_id', $debtId)
            ->orderByDesc('payment_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenantDebtPayment $payment): array => [
                'id' => $payment->id,
                'code' => $payment->code,
                'allocation_order' => $payment->allocation_order,
                'payment_amount' => (float) $payment->payment_amount,
                'principal_paid' => (float) $payment->principal_paid,
                'interest_paid' => (float) $payment->interest_paid,
                'change_amount' => (float) $payment->change_amount,
                'accept_account_id' => $payment->accept_account_id,
                'payment_at' => $payment->payment_at?->toISOString(),
            ])->all();
    }
}
