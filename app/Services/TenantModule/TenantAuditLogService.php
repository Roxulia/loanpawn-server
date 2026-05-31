<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\TenantAuditLogList;
use App\Models\CoreModule\TenantAuditLog;
use App\Repository\TenantAuditLogRepository;
use App\Services\BaseTenantService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

class TenantAuditLogService extends BaseTenantService
{
    public function __construct(
        private TenantAuditLogRepository $repository,
    ) {
    }

    public function log(
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?array $meta = null,
        ?int $actorUserId = null,
        ?int $actorAdminId = null,
    ): TenantAuditLog {
        return $this->repository->create([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'tenant_code' => $this->resolveCurrentTenantCode(),
            'actor_user_id' => $actorUserId ?? $this->resolveCurrentTenantUserId(),
            'actor_admin_id' => $actorAdminId ?? $this->resolveCurrentPlatformAdminId(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta' => $meta,
        ]);
    }

    public function getLog(CarbonInterface $startDate, CarbonInterface $endDate): TenantAuditLogList
    {
        return TenantAuditLogList::fromCollection(
            $this->repository->getBetweenDates(
                $startDate->copy()->startOfDay(),
                $endDate->copy()->endOfDay()
            )
        );
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function resolveCurrentPlatformAdminId(): ?int
    {
        return Auth::guard('platformadmin')->id();
    }
}
