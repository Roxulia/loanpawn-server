<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\Repository\LoanContractSlipRepository;
use App\Services\PawnModule\CollateralItemService;
use Illuminate\Support\Facades\DB;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Support\TenantContext;
use App\Support\TenantScopedCacheKeys;
use App\Models\PawnModule\PawnLoanContractSlip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ExpirationService
{
    public function __construct(
        private LoanContractSlipRepository $repository,
        private CollateralItemService $collateralItemService,
        private CustomerTrustScoreService $customerTrustScoreService,
        private AccountingDayBusinessClock $businessClock,
        private TenantContext $tenantContext,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {
    }

    public function checkExpire(?CarbonInterface $currentDate = null): int
    {
        $currentDate = $currentDate === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($currentDate)->startOfDay();

        $customers = $this->repository->overdueActiveSlipCustomers($currentDate);
        $expiredCount = DB::transaction(function () use ($currentDate): int {
            $count = $this->repository->expireOverdueActiveSlips($currentDate);
            $this->collateralItemService->expireForExpiredSlips();
            return $count;
        });

        $customers->each(function ($row): void {
            $this->customerTrustScoreService->recalculateForTenantCustomer(
                (int) $row->tenant_id,
                (int) $row->customer_id
            );
        });

        return $expiredCount;
    }

    public function checkCurrentTenant(): int
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) return 0;
        $today = $this->businessClock->now($tenantId)->startOfDay();
        $customers = $this->repository->overdueActiveSlipCustomers($today);
        $count = DB::transaction(function () use ($today, $tenantId): int {
            $expiredCount = $this->repository->expireCurrentTenantOverdueActiveSlips($today);
            $this->collateralItemService->expireForExpiredSlips($tenantId);
            return $expiredCount;
        });
        if ($count > 0) {
            $customers->each(fn ($row) => $this->customerTrustScoreService->recalculateForTenantCustomer((int) $row->tenant_id, (int) $row->customer_id));
            $this->tenantScopedCacheKeys->bumpVersion('loan-contract-slip-list');
        }

        return $count;
    }

    public function refreshExpiration(PawnLoanContractSlip $slip): PawnLoanContractSlip
    {
        if (strtolower((string) $slip->status) !== 'active' || $slip->expire_at === null) {
            if (strtolower((string) $slip->status) === 'expired') {
                $this->collateralItemService->expireForSlip((int) $slip->id);
            }
            return $slip;
        }
        $today = $this->businessClock->now((int) $slip->tenant_id)->startOfDay();
        if (! CarbonImmutable::parse($slip->expire_at)->lt($today)) return $slip;
        $didExpire = DB::transaction(function () use ($slip, $today): bool {
            $didExpire = $this->repository->expireSlipIfStillActive((int) $slip->id, $today);
            if ($didExpire || strtolower((string) $slip->status) === 'expired') {
                $this->collateralItemService->expireForSlip((int) $slip->id);
            }
            return $didExpire;
        });
        if ($didExpire) {
            $this->customerTrustScoreService->recalculateForCustomer((int) $slip->customer_id);
            $this->tenantScopedCacheKeys->bumpVersion('loan-contract-slip-list');
        }

        return $this->repository->reload($slip);
    }
}
