<?php

namespace App\Repository;

use App\Models\PawnModule\PawnInterestPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PawnInterestPaymentRepository
{
    public function history(int $perPage = 15): LengthAwarePaginator
    {
        return PawnInterestPayment::query()
            ->with(['slip', 'createdAccount.currency', 'acceptAccount.currency'])
            ->where('is_paid', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): PawnInterestPayment
    {
        return PawnInterestPayment::query()->create($data)->load(['createdAccount.currency', 'acceptAccount.currency']);
    }

    public function update(PawnInterestPayment $payment, array $data): PawnInterestPayment
    {
        $payment->update($data);

        return $payment->refresh()->load(['createdAccount.currency', 'acceptAccount.currency']);
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
        return PawnInterestPayment::query()->with(['createdAccount.currency', 'acceptAccount.currency'])->find($paymentId);
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findByIdsForSlip(int $slipId, array $paymentIds): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->whereIn('id', $paymentIds)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findLastAccruedInterestPayment(int $slipId): ?PawnInterestPayment
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->orderByDesc('end_period_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, PawnInterestPayment> */
    public function allForSlipWithLock(int $slipId): Collection
    {
        return PawnInterestPayment::query()
            ->where('slip_id', $slipId)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findInterestUntilDateBySlipId(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('start_period_at', '<=', $this->timestamp($date))
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findInterestUntilDateBySlipIdWithLock(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('start_period_at', '<=', $this->timestamp($date))
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findInterestAfterDateBySlipId(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('start_period_at', '>', $this->timestamp($date))
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findInterestAfterDateBySlipIdWithLock(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('start_period_at', '>', $this->timestamp($date))
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PawnInterestPayment>
     */
    public function findUnpaidInterestUntilDateBySlipId(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->where('start_period_at', '<=', $this->timestamp($date))
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->get();
    }

    public function findUnpaidInterestUntilDateBySlipIdWithLock(int $slipId, CarbonInterface|string $date): Collection
    {
        return PawnInterestPayment::query()
            ->with(['createdAccount.currency', 'acceptAccount.currency'])
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->where('start_period_at', '<=', $this->timestamp($date))
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
                    $query->where('start_period_at', '>', $paymentStart)
                    ->orWhere(function ($nested) use ($payment) {
                        $paymentStart = $payment->start_period_at;
                        $nested->where('start_period_at', '=', $paymentStart)
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
                    $query->where('start_period_at', '>', $paymentStart)
                    ->orWhere(function ($nested) use ($payment) {
                        $paymentStart = $payment->start_period_at;
                        $nested->where('start_period_at', '=', $paymentStart)
                            ->where('id', '>', $payment->id);
                    });
            })
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function timestamp(CarbonInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }
}
