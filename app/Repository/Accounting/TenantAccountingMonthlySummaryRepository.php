<?php

namespace App\Repository\Accounting;

use App\Models\CoreModule\TenantSetting;
use App\Models\PlatformModule\Tenant;
use App\Models\TenantAccountingMonthlySummary;
use App\Models\TenantAccountingTransactions;
use App\Models\ReportingCurrencyRecalculation;
use Illuminate\Support\Collection;

class TenantAccountingMonthlySummaryRepository
{
    public function tenantIds(): Collection
    {
        return Tenant::query()->orderBy('id')->pluck('id');
    }

    public function reportingCurrencyId(int $tenantId): ?int
    {
        $active = ReportingCurrencyRecalculation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ReportingCurrencyRecalculation::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($active !== null) {
            return (int) $active->previous_reporting_currency_id;
        }

        $value = TenantSetting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'currency_preferences')
            ->value('reporting_currency_id');

        return $value === null ? null : (int) $value;
    }

    public function movementRows(int $tenantId, string $monthStart, string $monthEnd): Collection
    {
        return TenantAccountingTransactions::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('business_date', [$monthStart, $monthEnd])
                    ->orWhere(function ($query) use ($monthStart, $monthEnd): void {
                        $query->whereNull('business_date')
                            ->whereBetween('occurred_at', ["{$monthStart} 00:00:00", "{$monthEnd} 23:59:59"]);
                    });
            })
            ->selectRaw('currency_id')
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'incoming' THEN amount ELSE 0 END) AS total_incoming")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'outgoing' THEN amount ELSE 0 END) AS total_outgoing")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'internal' THEN amount ELSE 0 END) AS total_internal")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'incoming' THEN COALESCE(reporting_amount, amount) ELSE 0 END) AS reporting_total_incoming")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'outgoing' THEN COALESCE(reporting_amount, amount) ELSE 0 END) AS reporting_total_outgoing")
            ->selectRaw("SUM(CASE WHEN transaction_direction = 'internal' THEN COALESCE(reporting_amount, amount) ELSE 0 END) AS reporting_total_internal")
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('currency_id')
            ->get();
    }

    public function replaceMonth(int $tenantId, string $monthStart, ?int $reportingCurrencyId, Collection $rows): void
    {
        TenantAccountingMonthlySummary::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('month_start', $monthStart)
            ->delete();

        foreach ($rows as $row) {
            $incoming = (float) $row->total_incoming;
            $outgoing = (float) $row->total_outgoing;
            $reportingIncoming = (float) $row->reporting_total_incoming;
            $reportingOutgoing = (float) $row->reporting_total_outgoing;

            TenantAccountingMonthlySummary::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'month_start' => $monthStart,
                'currency_id' => $row->currency_id === null ? null : (int) $row->currency_id,
                'reporting_currency_id' => $reportingCurrencyId,
                'total_incoming' => $incoming,
                'total_outgoing' => $outgoing,
                'total_internal' => (float) $row->total_internal,
                'net_movement' => $incoming - $outgoing,
                'reporting_total_incoming' => $reportingIncoming,
                'reporting_total_outgoing' => $reportingOutgoing,
                'reporting_total_internal' => (float) $row->reporting_total_internal,
                'reporting_net_movement' => $reportingIncoming - $reportingOutgoing,
                'transaction_count' => (int) $row->transaction_count,
                'calculated_at' => now(),
            ]);
        }
    }
}
