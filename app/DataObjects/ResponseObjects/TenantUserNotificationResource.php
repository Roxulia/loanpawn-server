<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\TenantUserNotification;

class TenantUserNotificationResource extends BaseDataObject
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public ?int $recalculationId,
        public array $data,
        public ?string $readAt,
        public string $createdAt,
    ) {}

    public static function fromModel(TenantUserNotification $notification): self
    {
        return new self(
            id: (string) $notification->id,
            type: $notification->type,
            status: $notification->status,
            recalculationId: $notification->reporting_currency_recalculation_id === null
                ? null
                : (int) $notification->reporting_currency_recalculation_id,
            data: $notification->data,
            readAt: $notification->read_at?->toISOString(),
            createdAt: $notification->created_at->toISOString(),
        );
    }
}
