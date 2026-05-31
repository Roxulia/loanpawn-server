<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Database\Eloquent\Collection;

class TenantAuditLogList extends BaseDataObject
{
    /**
     * @var TenantAuditLogDetail[]
     */
    public array $items;

    public static function fromCollection(Collection $auditLogs): self
    {
        $list = new self();
        $list->items = $auditLogs
            ->map(fn ($auditLog) => TenantAuditLogDetail::fromModel($auditLog))
            ->all();

        return $list;
    }
}
