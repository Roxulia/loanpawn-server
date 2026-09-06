<?php

namespace App\Repository;

use App\Models\PawnModule\PawnLoanContractSlip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /** @return Collection<int, PawnInterestPayment> */
    public function unpaidStartingAfter(
        int $slipId,
        int $tenantId,
        CarbonInterface|string $boundary,
        bool $lock = false,
    ): Collection
    {
        // Compare UTC timestamps so tenant-local day boundaries remain exact.
        return PawnInterestPayment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slip_id', $slipId)
            ->where('is_paid', false)
            ->where('start_period_at', '>', CarbonImmutable::parse($boundary)->utc())
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

}
