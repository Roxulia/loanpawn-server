<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountAssignmentUpdate;
use App\DataObjects\ResponseObjects\AssignedTenantUserSummary;
use App\DataObjects\ResponseObjects\FinancialAccountSummary;
use App\Exceptions\FinancialAccountAccessDenied;
use App\Exceptions\FinancialAccountAssignmentDenied;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantUserNotFound;
use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Repository\Accounting\FinancialAccountAssignmentRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Utility\MessageCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinancialAccountAssignmentService extends BaseTenantService
{
    public function __construct(
        private FinancialAccountAssignmentRepository $repository,
        private TenantUserPermissionService $permissionService,
    ) {}

    public function accountsForUser(TenantUser $user): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        if ((int) $user->tenant_id !== $tenantId) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceAssignmentInvalidAccounts));
        }

        return $this->repository->accountsForUser($tenantId, $user->id)
            ->map(fn (FinancialAccount $account) => FinancialAccountSummary::fromModel($account))
            ->all();
    }

    public function usersForAccount(FinancialAccount $account): array
    {
        $this->permissionService->authorizePermission('manage_financial_account_assignments');

        return $this->repository->usersForAccount($this->resolveCurrentTenantId(), $account->id)
            ->map(fn (TenantUser $user) => AssignedTenantUserSummary::fromModel($user))
            ->all();
    }

    public function updateForUser(string $userCode, FinancialAccountAssignmentUpdate $request): array
    {
        $this->permissionService->authorizePermission('manage_financial_account_assignments');
        $tenantId = $this->resolveCurrentTenantId();
        $targetUser = $this->repository->findUserByCode($tenantId, $userCode);
        if (! $targetUser) {
            throw new TenantUserNotFound;
        }
        $currentUser = Auth::guard('tenantuser')->user();

        if ($currentUser instanceof TenantUser && $currentUser->id === $targetUser->id) {
            throw new FinancialAccountAssignmentDenied(MessageCode::FinanceAssignmentSelfDenied);
        }

        if (mb_strtolower((string) $targetUser->role?->name) === 'owner') {
            throw new FinancialAccountAssignmentDenied(MessageCode::FinanceAssignmentOwnerDenied);
        }

        if ($targetUser->status !== 'active') {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceAssignmentActiveUserRequired));
        }

        $accountIds = array_values(array_unique(array_map('intval', $request->financialAccountIds)));
        $validAccountIds = $this->repository->validAccountIds($tenantId, $accountIds);
        sort($accountIds);
        sort($validAccountIds);
        if ($accountIds !== $validAccountIds) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceAssignmentInvalidAccounts));
        }

        DB::transaction(fn () => $this->repository->syncForUser($tenantId, $targetUser->id, $accountIds));

        return $this->accountsForUser($targetUser);
    }

    public function assertCurrentUserAssigned(FinancialAccount $account): void
    {
        $tenantId = $this->resolveCurrentTenantId();
        $currentUser = Auth::guard('tenantuser')->user();
        if (! $currentUser instanceof TenantUser
            || (int) $currentUser->tenant_id !== $tenantId
            || ! $this->repository->isAssigned($tenantId, $account->id, $currentUser->id)) {
            throw new FinancialAccountAccessDenied;
        }
    }

    public function assignOwnersToAccount(FinancialAccount $account): int
    {
        $owners = $this->repository->ownerUsers((int) $account->tenant_id);
        if ($owners->isEmpty()) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceAssignmentOwnerRequired));
        }

        return $this->repository->assignUsersToAccount(
            (int) $account->tenant_id,
            $account->id,
            $owners->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function removeForAccount(FinancialAccount $account): void
    {
        $this->repository->removeForAccount((int) $account->tenant_id, $account->id);
    }

    public function backfillOwners(?int $tenantId = null, bool $dryRun = false): array
    {
        $summary = ['tenants_checked' => 0, 'accounts_checked' => 0, 'assignments_created' => 0];

        foreach ($this->repository->tenantIds($tenantId) as $currentTenantId) {
            $summary['tenants_checked']++;
            $owners = $this->repository->ownerUsers((int) $currentTenantId);
            foreach ($this->repository->accountIds((int) $currentTenantId) as $accountId) {
                $summary['accounts_checked']++;
                foreach ($owners as $owner) {
                    if (! $this->repository->isAssigned((int) $currentTenantId, $accountId, $owner->id)) {
                        $summary['assignments_created']++;
                        if (! $dryRun) {
                            $this->repository->assignUsersToAccount((int) $currentTenantId, $accountId, [$owner->id]);
                        }
                    }
                }
            }
        }

        return $summary;
    }
}
