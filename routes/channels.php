<?php

use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformUser;
use App\Models\CoreModule\TenantUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}.user.{tenantUserId}.notifications', function ($account, int $tenantId, int $tenantUserId): bool {
    return $account instanceof TenantUser
        && (int) $account->tenant_id === $tenantId
        && (int) $account->id === $tenantUserId;
}, ['guards' => ['tenantuser']]);

Broadcast::channel('platform.admin.issued-tickets', function ($account): bool {
    return $account instanceof PlatformAdmin;
}, ['guards' => ['platformadmin']]);

Broadcast::channel('platform.user.{platformUserId}.customer-service', function ($account, int $platformUserId): bool {
    return $account instanceof PlatformUser
        && (int) $account->id === (int) $platformUserId;
}, ['guards' => ['platformuser']]);

Broadcast::channel('platform.support-ticket.{ticketId}', function ($account, int $ticketId): bool {
    if ($account instanceof PlatformAdmin) {
        return true;
    }

    if (! $account instanceof PlatformUser) {
        return false;
    }

    $ticket = PlatformSupportTicket::query()
        ->where('is_deleted', false)
        ->find($ticketId);

    return $ticket !== null
        && (int) $ticket->platform_user_id === (int) $account->id;
}, ['guards' => ['platformadmin', 'platformuser']]);
