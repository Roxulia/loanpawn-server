<?php

namespace App\Repository;

use App\Models\PawnModule\PawnInterestPayment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PawnInterestPaymentRepository
{

    public function history(int $perPage = 15): LengthAwarePaginator
    {
        return PawnInterestPayment::query()
            ->with('slip')
            ->where("is_paid", true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): PawnInterestPayment
    {
        return PawnInterestPayment::query()->create($data);
    }

    public function update(PawnInterestPayment $payment, array $data): PawnInterestPayment
    {
        $payment->update($data);

        return $payment->refresh();
    }

    public function updateWithLock(PawnInterestPayment $payment, array $data): PawnInterestPayment
    {
        $lockedPayment = PawnInterestPayment::query()
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedPayment, $data);
    }

    public function delete(PawnInterestPayment $payment): void
    {
        $payment->delete();
    }

    public function findById(int $paymentId): ?PawnInterestPayment
    {
        return PawnInterestPayment::query()->find($paymentId);
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findByIdsForSlip(int $slipId, array $paymentIds): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->whereIn('id', $paymentIds)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findLastAccruedInterestPayment(int $slipId): ?PawnInterestPayment
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->orderByDesc('end_period_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findInterestUntilDateBySlipId(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->whereDate('start_period_at', '<=', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findInterestUntilDateBySlipIdWithLock(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->whereDate('start_period_at', '<=', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findInterestAfterDateBySlipId(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->whereDate('start_period_at', '>', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findInterestAfterDateBySlipIdWithLock(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->whereDate('start_period_at', '>', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findUnpaidInterestUntilDateBySlipId(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->whereDate('start_period_at', '<=', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findUnpaidInterestUntilDateBySlipIdWithLock(int $slipId, string $date): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->whereDate('start_period_at', '<=', $date)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findPaymentsAfterPayment(int $slipId, PawnInterestPayment $payment): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->where(function ($query) use ($payment) {
                $paymentStart = $payment->start_period_at;
                $query->whereDate('start_period_at', '>', $paymentStart)
                    ->orWhere(function ($nested) use ($payment) {
                        $paymentStart = $payment->start_period_at;
                        $nested->whereDate('start_period_at', '=', $paymentStart)
                            ->where('id', '>', $payment->id);
                    });
            })
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findPaymentsAfterPaymentWithLock(int $slipId, PawnInterestPayment $payment): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->where(function ($query) use ($payment) {
                $paymentStart = $payment->start_period_at;
                $query->whereDate('start_period_at', '>', $paymentStart)
                    ->orWhere(function ($nested) use ($payment) {
                        $paymentStart = $payment->start_period_at;
                        $nested->whereDate('start_period_at', '=', $paymentStart)
                            ->where('id', '>', $payment->id);
                    });
            })
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
