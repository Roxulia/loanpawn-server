<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\DataObjects\ResponseObjects\LoanContractSlipDetail;
use App\DataObjects\ResponseObjects\LoanContractSlipListPage;
use App\Exceptions\TenantNotFound;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\LoanContractSlipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Support\Facades\Cache;

class LookUpService extends BaseTenantService
{
    protected const LOAN_CONTRACT_SLIP_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private LoanContractSlipRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {
    }

    public function list(int $perPage = 15): LoanContractSlipListPage
    {
        $this->permissionService->authorizeLoanContractList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('loan-contract-slip-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('loan-contract-slip-list', $version, $page, $perPage),
            now()->addSeconds(self::LOAN_CONTRACT_SLIP_LIST_CACHE_TTL_SECONDS),
            fn () => LoanContractSlipListPage::fromPaginator($this->repository->paginate($perPage))
        );
    }

    public function findBySlipNo(string $slipNo): LoanContractSlipDetail
    {
        $this->permissionService->authorizeLoanContractList();
        $slip = $this->repository->findBySlipNo($slipNo);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return LoanContractSlipDetail::fromModel($slip);
    }

    public function findModelById(int $slipId): PawnLoanContractSlip
    {
        return $this->findSlipForCurrentTenant($slipId);
    }

    public function findModelByIdWithLock(int $slipId): PawnLoanContractSlip
    {
        $slip = $this->repository->findByIdWithLock($slipId);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return $slip;
    }

    public function findModelBySlipNo(string $slipNo): PawnLoanContractSlip
    {
        $slip = $this->repository->findBySlipNo($slipNo);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return $slip;
    }

    public function findModelBySlipNoWithLock(string $slipNo): PawnLoanContractSlip
    {
        $slip = $this->repository->findBySlipNoWithLock($slipNo);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return $slip;
    }

    protected function findSlipForCurrentTenant(int $slipId): PawnLoanContractSlip
    {
        $slip = $this->repository->findById($slipId);

        if ($slip === null) {
            throw new TenantNotFound('Loan contract slip not found.');
        }

        return $slip;
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
