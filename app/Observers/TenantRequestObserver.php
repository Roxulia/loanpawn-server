<?php

namespace App\Observers;

use App\Jobs\Telegram\SendTenantRequestTelegramNotificationJob;
use App\Models\PlatformModule\TenantRequest;

class TenantRequestObserver
{
    public function created(TenantRequest $tenantRequest): void
    {
        if (! $this->enabled()) {
            return;
        }

        dispatch(new SendTenantRequestTelegramNotificationJob($tenantRequest->id, 'created'))->afterCommit();
    }

    public function updated(TenantRequest $tenantRequest): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! $this->hasMeaningfulChanges($tenantRequest)) {
            return;
        }

        dispatch(new SendTenantRequestTelegramNotificationJob($tenantRequest->id, 'updated'))->afterCommit();
    }

    private function hasMeaningfulChanges(TenantRequest $tenantRequest): bool
    {
        $ignored = ['updated_at', 'update_key'];
        $changed = array_keys($tenantRequest->getChanges());

        return count(array_diff($changed, $ignored)) > 0;
    }

    private function enabled(): bool
    {
        return (bool) config('services.telegram.notifications_enabled');
    }
}
