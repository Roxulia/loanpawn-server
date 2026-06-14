<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantUserUpdate;
use App\Exceptions\TenantUserAccessDenied;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\CoreModule\TenantUserPermission;
use App\Models\PlatformModule\PlatformUser;
use App\Repository\TenantUserPermissionRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use App\Support\TenantPermissionColumns;
use App\Utility\MessageCode;
use Illuminate\Support\Facades\Auth;

class TenantUserPermissionService extends BaseTenantService
{
    public function __construct(
        private TenantLookupService $tenantLookupService,
        private TenantUserPermissionRepository $tenantUserPermissionRepository,
    ) {
    }

    public function authorizeManagement(): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->canManageAllUsers($tenantId)) {
            return;
        }

        throw new TenantUserAccessDenied();
    }

    public function authorizePermission(string $permission): void
    {
        $this->authorizeTenantPermission($permission);
    }

    public function authorizeAnyPermission(array $permissions): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->isOwningPlatformUser($tenantId)) {
            return;
        }

        $tenantUser = Auth::guard('tenantuser')->user();

        if (! $tenantUser instanceof TenantUser || $tenantUser->tenant_id !== $tenantId) {
            throw new TenantUserAccessDenied();
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($tenantUser, $permission)) {
                return;
            }
        }

        throw new TenantUserAccessDenied();
    }

    public function resolveUpdateScope(TenantUser $targetUser, TenantUserUpdate $request): bool
    {
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->canManageAllUsers($tenantId)) {
            return true;
        }

        $currentTenantUser = Auth::guard('tenantuser')->user();

        if (! $currentTenantUser instanceof TenantUser || $currentTenantUser->tenant_id !== $tenantId) {
            throw new TenantUserAccessDenied($this->responseMessage(MessageCode::NotTenantUser));
        }

        $isSelfUpdate = $currentTenantUser->id === $targetUser->id;
        $hasAdminUpdate = $this->hasPermission($currentTenantUser, 'update_user_admin');
        $hasAllUpdate = $this->hasPermission($currentTenantUser, 'update_user_all');
        $hasOwnUpdate = $this->hasPermission($currentTenantUser, 'update_user_own');
        $updatesAdminFields = $request->roleId !== null || $request->status !== null;
        $updatesProfileFields = $request->name !== null
            || $request->nrc !== null
            || $request->email !== null
            || $request->phone !== null
            || $request->address !== null
            || $request->password !== null;

        if ($updatesAdminFields && ! $hasAdminUpdate) {
            throw new TenantUserAccessDenied(null);
        }

        if ($updatesProfileFields && ! $hasAllUpdate && ! $hasAdminUpdate && (! $isSelfUpdate || ! $hasOwnUpdate)) {
            throw new TenantUserAccessDenied(null);
        }

        return $hasAdminUpdate;
    }

    public function canUpdateAdminFields(TenantUser $tenantUser): bool
    {
        return $this->hasPermission($tenantUser, 'update_user_admin');
    }

    public function canUpdateAllUsers(TenantUser $tenantUser): bool
    {
        return $this->hasPermission($tenantUser, 'update_user_all');
    }

    public function createPermissionFromRole(TenantUser $tenantUser): TenantUserPermission
    {
        $tenantUser->loadMissing('role');

        return $this->tenantUserPermissionRepository->createForUser(
            $tenantUser,
            $this->permissionsFromRole($tenantUser->role)
        );
    }

    public function updateUserPermissions(TenantUser $tenantUser, array $permissions): TenantUserPermission
    {
        $this->authorizeTenantPermission('update_user_admin');
        $this->authorizeCanManageTargetRole($tenantUser);

        return $this->tenantUserPermissionRepository->updateOrCreateForUser(
            $tenantUser,
            TenantPermissionColumns::normalizePayload($permissions)
        );
    }

    public function authorizeUserList(): void
    {
        $this->authorizeTenantPermission('list_user');
    }

    public function authorizeUserCreate(): void
    {
        $this->authorizeTenantPermission('create_user');
    }

    public function authorizeUserUpdate(): void
    {
        $this->authorizeTenantPermission('update_user_all');
    }

    public function authorizeUserDelete(): void
    {
        $this->authorizeTenantPermission('delete_user');
    }

    public function authorizeCustomerList(): void
    {
        $this->authorizeTenantPermission('list_customer');
    }

    public function authorizeCustomerCreate(): void
    {
        $this->authorizeTenantPermission('create_customer');
    }

    public function authorizeCustomerUpdate(): void
    {
        $this->authorizeTenantPermission('update_customer');
    }

    public function authorizeCustomerDelete(): void
    {
        $this->authorizeTenantPermission('delete_customer');
    }

    public function authorizeCollateralList(): void
    {
        $this->authorizeTenantPermission('list_collateral');
    }

    public function authorizeCollateralCreate(): void
    {
        $this->authorizeTenantPermission('create_collateral');
    }

    public function authorizeCollateralUpdate(): void
    {
        $this->authorizeTenantPermission('update_collateral');
    }

    public function authorizeCollateralDelete(): void
    {
        $this->authorizeTenantPermission('delete_collateral');
    }

    public function authorizeAccountingList(): void
    {
        $this->authorizeTenantPermission('list_accounting');
    }

    public function authorizeDashboardRead(): void
    {
        $this->authorizeTenantPermission('dashboard');
    }

    public function authorizeExpenseList(): void
    {
        $this->authorizeTenantPermission('list_expense');
    }

    public function authorizeExpenseCreate(): void
    {
        $this->authorizeTenantPermission('create_expense');
    }

    public function authorizeExpenseUpdate(): void
    {
        $this->authorizeTenantPermission('update_expense');
    }

    public function authorizeExpenseDelete(): void
    {
        $this->authorizeTenantPermission('delete_expense');
    }

    public function authorizeDebtList(): void
    {
        $this->authorizeTenantPermission('list_debt');
    }

    public function authorizeDebtCreate(): void
    {
        $this->authorizeTenantPermission('create_debt');
    }

    public function authorizeDebtUpdate(): void
    {
        $this->authorizeTenantPermission('update_debt');
    }

    public function authorizeDebtDelete(): void
    {
        $this->authorizeTenantPermission('delete_debt');
    }

    public function authorizeLoanContractList(): void
    {
        $this->authorizeTenantPermission('list_loan_contract');
    }

    public function authorizeLoanContractCreate(): void
    {
        $this->authorizeTenantPermission('create_loan_contract');
    }

    public function authorizeLoanContractDelete(): void
    {
        $this->authorizeTenantPermission('delete_loan_contract');
    }

    public function authorizeSlipDocumentManage(): void
    {
        $this->authorizeTenantPermission('manage_slip_document');
    }

    protected function canManageAllUsers(int $tenantId): bool
    {
        if ($this->isOwningPlatformUser($tenantId)) {
            return true;
        }

        $tenantUser = Auth::guard('tenantuser')->user();

        return $tenantUser instanceof TenantUser
            && $tenantUser->tenant_id === $tenantId
            && $this->hasPermission($tenantUser, 'access_all');
    }

    protected function authorizeTenantPermission(string $permission): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->isOwningPlatformUser($tenantId)) {
            return;
        }

        $tenantUser = Auth::guard('tenantuser')->user();

        if (
            $tenantUser instanceof TenantUser
            && $tenantUser->tenant_id === $tenantId
            && $this->hasPermission($tenantUser, $permission)
        ) {
            return;
        }

        throw new TenantUserAccessDenied();
    }

    protected function isOwningPlatformUser(int $tenantId): bool
    {
        $platformUser = Auth::guard('platformuser')->user();

        if (! $platformUser instanceof PlatformUser) {
            return false;
        }

        $tenant = $this->tenantLookupService->findById($tenantId);

        return (int) $tenant->platform_user_id === (int) $platformUser->id;
    }

    public function hasPermission(TenantUser $tenantUser, string $permission): bool
    {
        $tenantUser->loadMissing(['role', 'permission']);

        $enabledPermissions = TenantPermissionColumns::effectivePermissions(array_unique([
            ...TenantPermissionColumns::enabledFromModel($tenantUser->role),
            ...TenantPermissionColumns::enabledFromModel($tenantUser->permission),
        ]));

        if (in_array('access_all', $enabledPermissions, true)) {
            return true;
        }

        return in_array($permission, $enabledPermissions, true);
    }

    protected function permissionsFromRole(?TenantRole $role): array
    {
        if ($role === null) {
            return TenantPermissionColumns::booleanPayload([]);
        }

        return TenantPermissionColumns::booleanPayload(TenantPermissionColumns::enabledFromModel($role));
    }

    protected function authorizeCanManageTargetRole(TenantUser $targetUser): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        if ($this->isOwningPlatformUser($tenantId)) {
            return;
        }

        $updater = Auth::guard('tenantuser')->user();

        if (! $updater instanceof TenantUser || $updater->tenant_id !== $tenantId) {
            throw new TenantUserAccessDenied();
        }

        if ($this->hasPermission($updater, 'access_all')) {
            return;
        }

        $targetUser->loadMissing('role');

        foreach (TenantPermissionColumns::enabledFromModel($targetUser->role) as $rolePermission) {
            if (! $this->hasPermission($updater, $rolePermission)) {
                throw new TenantUserAccessDenied(null);
            }
        }
    }
}
