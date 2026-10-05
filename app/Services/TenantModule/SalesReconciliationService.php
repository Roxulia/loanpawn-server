<?php

namespace App\Services\TenantModule;

use App\Repository\SalesReconciliationRepository;
use App\Services\BaseTenantService;

class SalesReconciliationService extends BaseTenantService
{
    public function __construct(private SalesReconciliationRepository $repository) {}

    // Combine tenant-scoped linkage checks for delivery, returns, and financial records.
    public function issues(): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        return array_merge(
            $this->repository->deliveryIssues($tenantId),
            $this->repository->returnIssues($tenantId),
            $this->repository->financialIssues($tenantId),
        );
    }
}