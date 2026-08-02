<?php

namespace App\Repository;

use App\Models\PawnModule\PawnLoanContractSlip;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class LoanContractSlipRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return PawnLoanContractSlip::query()
            ->with(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->create($data)
            ->load(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType']);
    }

    public function markSlipItemsDeleted(PawnLoanContractSlip $slip): void
    {
        $slip->slipItems()->update([
            'is_deleted' => true,
            'item_status' => 'deleted',
        ]);
    }

    public function delete(PawnLoanContractSlip $slip): void
    {
        $slip->delete();
    }

    public function update(PawnLoanContractSlip $slip, array $data): PawnLoanContractSlip
    {
        $slip->update($data);

        return $slip->refresh()->load(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType']);
    }

    public function updateWithLock(PawnLoanContractSlip $slip, array $data): PawnLoanContractSlip
    {
        $lockedSlip = PawnLoanContractSlip::query()
            ->whereKey($slip->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedSlip, $data);
    }

    public function findBySlipNo(string $slipNo): ?PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->with(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->where('slip_no', $slipNo)
            ->first();
    }

    public function findBySlipNoWithLock(string $slipNo): ?PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->with(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->where('slip_no', $slipNo)
            ->lockForUpdate()
            ->first();
    }

    public function findById(int $slipId): ?PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->with(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->find($slipId);
    }

    public function findByIdWithLock(int $slipId): ?PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()
            ->with(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->whereKey($slipId)
            ->lockForUpdate()
            ->first();
    }

    public function latestSlipNoForDate(string $datePrefix): ?string
    {
        return PawnLoanContractSlip::query()
            ->where('slip_no', 'like', $datePrefix.'%')
            ->orderByDesc('slip_no')
            ->value('slip_no');
    }

    public function reload(PawnLoanContractSlip $slip): PawnLoanContractSlip
    {
        return $slip->refresh()->load(['customer', 'interestType', 'slipItems.materialType', 'slipItems.itemCategoryType']);
    }

    public function expireOverdueActiveSlips(CarbonInterface $currentDate): int
    {
        return PawnLoanContractSlip::query()
            ->withoutGlobalScope('tenant')
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<', $currentDate->toDateString())
            ->update([
                'status' => 'expired',
            ]);
    }

    public function overdueActiveSlipCustomers(CarbonInterface $currentDate): Collection
    {
        $tenantId = app(TenantContext::class)->id();
        $query = $tenantId === null
            ? PawnLoanContractSlip::query()->withoutGlobalScope('tenant')
            : PawnLoanContractSlip::query();

        return $query
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereDate('expire_at', '<', $currentDate->toDateString())
            ->select('tenant_id', 'customer_id')
            ->distinct()
            ->get();
    }

}
