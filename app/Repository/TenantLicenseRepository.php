<?php

namespace App\Repository;

use App\Models\PlatformModule\TenantLicense;
use App\Models\PlatformModule\TenantLicensePlanTransition;
use App\Models\PlatformModule\LicenseStatusLog;
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

    public function findByTenantId(int $tenantId): ?TenantLicense
    {
        $res = TenantLicense::query()
            ->where('tenant_id', $tenantId)
            ->first();

        return $res;
    }

    public function findByTenantIdForUpdate(int $tenantId): ?TenantLicense
    {
        return TenantLicense::query()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function update(TenantLicense $license, array $data): TenantLicense
    {
        $license->update($data);

        return $license->refresh();
    }

    public function createStatusLog(array $data): LicenseStatusLog
    {
        return LicenseStatusLog::query()->create($data);
    }

    public function findByLicenseKey(string $licenseKey): ?TenantLicense
    {
        return TenantLicense::query()
            ->with('tenant')
            ->where('license_key', $licenseKey)
            ->first();
    }

    public function isLicenseExisted(string $licenseKey): bool
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

    public function resetCurrentMonthSlipCounts(): int
    {
        return TenantLicense::query()->update([
            'current_month_slip_count' => 0,
        ]);
    }

    public function incrementCounter(int $tenantId, string $attribute): void
    {
        TenantLicense::query()
            ->where('tenant_id', $tenantId)
            ->increment($attribute);
    }

    public function decrementCounter(int $tenantId, string $attribute): void
    {
        TenantLicense::query()
            ->where('tenant_id', $tenantId)
            ->where($attribute, '>', 0)
            ->decrement($attribute);
    }

    public function createPlanTransition(array $data): TenantLicensePlanTransition
    {
        return TenantLicensePlanTransition::query()->create($data);
    }

    public function hasScheduledTransition(int $licenseId): bool
    {
        return TenantLicensePlanTransition::query()
            ->where('tenant_license_id', $licenseId)
            ->where('status', 'scheduled')
            ->where('is_deleted', false)
            ->exists();
    }

    public function activateDuePlanTransitions(?Carbon $currentDate = null): int
    {
        $currentDate = $currentDate ?? now();
        $transitionIds = TenantLicensePlanTransition::query()
            ->where('status', 'scheduled')
            ->where('is_deleted', false)
            ->where('starts_at', '<=', $currentDate)
            ->pluck('id');
        $activated = 0;

        foreach ($transitionIds as $transitionId) {
            DB::transaction(function () use ($transitionId, $currentDate, &$activated): void {
                $transition = TenantLicensePlanTransition::query()->lockForUpdate()->find($transitionId);

                if (! $transition || $transition->status !== 'scheduled' || $transition->is_deleted) {
                    return;
                }

                $license = TenantLicense::query()->lockForUpdate()->findOrFail($transition->tenant_license_id);
                $license->update([
                    'plan_id' => $transition->to_plan_id,
                    'plan_type' => $transition->to_plan_type,
                    'status' => 'active',
                    'starts_at' => $transition->starts_at,
                    'expires_at' => $transition->expires_at,
                    'update_key' => $license->update_key + 1,
                ]);
                if ($transition->toPlan?->category_id !== null) {
                    $license->tenant()->update(['category_id' => $transition->toPlan->category_id]);
                }
                $transition->update([
                    'status' => 'activated',
                    'activated_at' => $currentDate,
                    'update_key' => $transition->update_key + 1,
                ]);
                $activated++;
            });
        }

        return $activated;
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
