<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\Repository\LoanContractSlipRepository;
use App\Services\TenantModule\CustomerTrustScoreService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ExpirationService
{
    public function __construct(
        private LoanContractSlipRepository $repository,
        private CustomerTrustScoreService $customerTrustScoreService,
    ) {
    }

    public function checkExpire(?CarbonInterface $currentDate = null): int
    {
        $currentDate = $currentDate === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($currentDate)->startOfDay();

        $customers = $this->repository->overdueActiveSlipCustomers($currentDate);
        $expiredCount = $this->repository->expireOverdueActiveSlips($currentDate);

        $customers->each(function ($row): void {
            $this->customerTrustScoreService->recalculateForTenantCustomer(
                (int) $row->tenant_id,
                (int) $row->customer_id
            );
        });

        return $expiredCount;
    }
}
