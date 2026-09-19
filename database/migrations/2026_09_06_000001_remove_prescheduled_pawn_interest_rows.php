<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fallbackTimezone = 'Asia/Yangon';
        // Resolve every tenant represented by an unpaid pawn-interest row.
        $tenantIds = DB::table('pawn_interest_payments')
            ->where('is_paid', false)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');
        $timezones = DB::table('tenant_settings')
            ->where('key', 'timezone')
            ->pluck('value', 'tenant_id');

        foreach ($tenantIds as $tenantId) {
            // Convert the tenant's current local day end into the stored UTC boundary.
            $timezone = (string) ($timezones[$tenantId] ?? $fallbackTimezone);
            if (! in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = $fallbackTimezone;
            }
            $currentLocalDayEnd = CarbonImmutable::now($timezone)->endOfDay()->utc();

            // Preserve paid and already-due history while removing pre-scheduled rows.
            DB::table('pawn_interest_payments')
                ->where('tenant_id', $tenantId)
                ->where('is_paid', false)
                ->where('start_period_at', '>', $currentLocalDayEnd)
                ->delete();
        }
    }

    public function down(): void
    {
        // Removed future schedules are intentionally rebuilt incrementally when due.
    }
};
