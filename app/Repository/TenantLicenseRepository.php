<?php

namespace App\Repository;

use App\Models\PlatformModule\TenantLicense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TenantLicenseRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findByTenantId(int $tenantId) : ?TenantLicense
    {
        $res = TenantLicense::query()
            ->where('tenant_id', $tenantId)
            ->first();
        return $res;
    }

    public function findByLicenseKey(string $licenseKey): ?TenantLicense
    {
        return TenantLicense::query()
            ->with('tenant')
            ->where('license_key', $licenseKey)
            ->first();
    }

    public function isLicenseExisted(string $licenseKey) : bool
    {
        return TenantLicense::query()->where('license_key', $licenseKey)->exists();
    }

    public function checkExpire(?Carbon $currentDate = null): int
    {
        $currentDate = $currentDate ?? now();

        return DB::update(
            "UPDATE tenant_licenses
            SET status = CASE
                WHEN expires_at < ? THEN 'expired'
                ELSE status
            END
            WHERE expires_at IS NOT NULL
              AND status <> 'expired'
              AND status = 'active'
              AND expires_at < ?",
            [$currentDate, $currentDate]
        );
    }

    /**
     * @return Collection<int, TenantLicense>
     */
    public function activeLicensesExpiringInDays(int $days, ?Carbon $currentDate = null): Collection
    {
        $currentDate = $currentDate ?? now();
        $targetDate = $currentDate->copy()->addDays($days);

        return TenantLicense::query()
            ->with(['tenant.owner'])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [
                $targetDate->copy()->startOfDay(),
                $targetDate->copy()->endOfDay(),
            ])
            ->get();
    }

    public function hasNotificationLog(int $licenseId, string $notificationType, int $thresholdDays): bool
    {
        return DB::table('tenant_license_notification_logs')
            ->where('license_id', $licenseId)
            ->where('notification_type', $notificationType)
            ->where('threshold_days', $thresholdDays)
            ->exists();
    }

    public function createNotificationLog(int $licenseId, string $notificationType, int $thresholdDays): void
    {
        DB::table('tenant_license_notification_logs')->insert([
            'license_id' => $licenseId,
            'notification_type' => $notificationType,
            'threshold_days' => $thresholdDays,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
