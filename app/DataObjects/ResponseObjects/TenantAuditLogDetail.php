<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantAuditLog;

class TenantAuditLogDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public int $updateKey;
    public ?int $actorUserId;
    public ?int $actorAdminId;
    public string $action;
    public string $targetType;
    public ?int $targetId;
    public ?array $meta;
    public ?string $createdAt;

    public static function fromModel(TenantAuditLog $auditLog): self
    {
        $detail = new self();
        $detail->id = $auditLog->id;
        $detail->tenantId = $auditLog->tenant_id;
        $detail->updateKey = (int) $auditLog->update_key;
        $detail->actorUserId = $auditLog->actor_user_id;
        $detail->actorAdminId = $auditLog->actor_admin_id;
        $detail->action = $auditLog->action;
        $detail->targetType = $auditLog->target_type;
        $detail->targetId = $auditLog->target_id;
        $detail->meta = $auditLog->meta;
        $detail->createdAt = $auditLog->created_at?->toISOString();

        return $detail;
    }
}
