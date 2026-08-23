<?php

namespace App\Events;

use App\DataObjects\ResponseObjects\TenantUserNotificationResource;
use App\Models\TenantUserNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TenantUserNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(
            "tenant.{$this->notification->tenant_id}.user.{$this->notification->tenant_user_id}.notifications"
        )];
    }

    public function broadcastAs(): string
    {
        return 'tenant.notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => TenantUserNotificationResource::fromModel($this->notification)->toArray(),
        ];
    }
}
