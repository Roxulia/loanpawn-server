<?php

namespace App\Services\TenantModule;

use App\Exceptions\RequiredValueMissing;
use App\Repository\TenantRoleRepository;
use App\Support\TenantContext;
use App\Utility\MessageCodes;

class TenantRoleService
{
    public function __construct(
        private TenantRoleRepository $repository,
    ) {
    }

    public function resolveDefaultRoleIdByName(string $roleName): int
    {
        $role = $this->repository->findDefaultByName($roleName);

        if ($role === null) {
            throw new RequiredValueMissing(MessageCodes::$messages['eb027']);
        }

        return $role->id;
    }

    public function resolveRoleIdByName(string $roleName, ?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? $this->resolveOptionalCurrentTenantId();

        $role = $this->repository->findAccessibleByName($tenantId, $roleName);

        if ($role === null) {
            throw new RequiredValueMissing(MessageCodes::$messages['eb027']);
        }

        return $role->id;
    }

    public function ensureRoleExists(int $roleId, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->resolveOptionalCurrentTenantId();

        if (! $this->repository->existsAccessible($roleId, $tenantId)) {
            throw new RequiredValueMissing(MessageCodes::$messages['eb027']);
        }
    }

    protected function resolveOptionalCurrentTenantId(): ?int
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        return $tenantContext->id();
    }

}
