<?php

namespace App\Services\TenantModule;

use App\Exceptions\RequiredValueMissing;
use App\Repository\TenantRoleRepository;
use App\Services\BaseTenantService;
use App\Support\TenantContext;
use App\Utility\MessageCode;

class TenantRoleService extends BaseTenantService
{
    public function __construct(
        private TenantRoleRepository $repository,
    ) {
    }

    public function listOptions(?int $tenantId = null)
    {
        $tenantId = $tenantId ?? $this->resolveOptionalCurrentTenantId();

        return $this->repository->listAccessible($tenantId)
            ->map(fn ($role) => [
                'role_id' => $role->id,
                'role_name' => $role->name,
            ])
            ->values();
    }

    public function resolveDefaultRoleIdByName(string $roleName): int
    {
        $role = $this->repository->findDefaultByName($roleName);

        if ($role === null) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::RoleNotFound));
        }

        return $role->id;
    }

    public function resolveRoleIdByName(string $roleName, ?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? $this->resolveOptionalCurrentTenantId();

        $role = $this->repository->findAccessibleByName($tenantId, $roleName);

        if ($role === null) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::RoleNotFound));
        }

        return $role->id;
    }

    public function ensureRoleExists(int $roleId, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->resolveOptionalCurrentTenantId();

        if (! $this->repository->existsAccessible($roleId, $tenantId)) {
            throw new RequiredValueMissing($this->responseMessage(MessageCode::RoleNotFound));
        }
    }

    protected function resolveOptionalCurrentTenantId(): ?int
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        return $tenantContext->id();
    }

}
