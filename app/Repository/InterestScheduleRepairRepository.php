<?php

namespace App\Repository;

use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use Illuminate\Database\Eloquent\Collection;

class InterestScheduleRepairRepository
{
    public function countNonActiveSlips(?int $tenantId = null, ?string $slipNo = null): int
    {
        return PawnLoanContractSlip::query()
            ->withoutGlobalScope('tenant')
            ->where('status', '!=', 'active')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($slipNo !== null, fn ($query) => $query->where('slip_no', $slipNo))
            ->count();
    }

    public function chunkActiveSlips(int $size, callable $callback, ?int $tenantId = null, ?string $slipNo = null): void
    {
        PawnLoanContractSlip::query()
            ->withoutGlobalScope('tenant')
            ->with('interestType')
            ->where('status', 'active')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($slipNo !== null, fn ($query) => $query->where('slip_no', $slipNo))
            ->orderBy('id')
            ->chunkById($size, $callback);
    }

    public function lockActiveSlip(int $slipId, int $tenantId): ?PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->withoutGlobalScope('tenant')
            ->with('interestType')
            ->whereKey($slipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
    }

    public function latestPaidPayment(int $slipId, int $tenantId, bool $lock = false): ?PawnInterestPayment
    {
        return PawnInterestPayment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slip_id', $slipId)
            ->where('is_paid', true)
            ->whereNotNull('payment_at')
            ->orderByDesc('payment_at')
            ->orderByDesc('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** @return Collection<int, PawnInterestPayment> */
    public function unpaidAfterPayment(int $slipId, int $tenantId, string $paymentDate, bool $lock = false): Collection
    {
        return PawnInterestPayment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->whereDate('start_period_at', '>', $paymentDate)
            ->orderBy('start_period_at')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    public function deletePayments(Collection $payments): int
    {
        $count = $payments->count();
        foreach ($payments as $payment) {
            $payment->delete();
        }

        return $count;
    }

    public function updateSlip(PawnLoanContractSlip $slip, array $data): PawnLoanContractSlip
    {
        $slip->forceFill($data)->save();

        return $slip->refresh()->load('interestType');
    }
}
