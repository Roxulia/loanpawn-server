<?php

namespace App\Repository;

use App\Enums\AccountingCategory;
use App\Models\PlatformModule\Tenant;
use App\Models\TenantAccountingDay;
use App\Models\TenantAccountingDaySchedule;
use App\Models\TenantAccountingDaySummary;
use App\Models\TenantAccountingTransactions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TenantAccountingDayRepository
{
    public function lockTenant(int $tenantId): void
    {
        Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return TenantAccountingDay::query()
            ->with('summaries.currency')
            ->orderByDesc('business_date')
            ->paginate($perPage);
    }

    public function findForTenantDate(int $tenantId, string $businessDate, bool $lock = false): ?TenantAccountingDay
    {
        return TenantAccountingDay::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('business_date', $businessDate)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function latestForTenant(int $tenantId, bool $lock = false): ?TenantAccountingDay
    {
        return TenantAccountingDay::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('business_date')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function create(array $data): TenantAccountingDay
    {
        return TenantAccountingDay::query()->withoutGlobalScopes()->create($data);
    }

    public function update(TenantAccountingDay $day, array $data): TenantAccountingDay
    {
        $day->update($data);

        return $day->refresh();
    }

    public function replaceSummaries(TenantAccountingDay $day, array $summaries): void
    {
        TenantAccountingDaySummary::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $day->tenant_id)
            ->where('accounting_day_id', $day->id)
            ->delete();

        foreach ($summaries as $summary) {
            TenantAccountingDaySummary::query()->withoutGlobalScopes()->create([
                'tenant_id' => $day->tenant_id,
                'accounting_day_id' => $day->id,
                ...$summary,
            ]);
        }
    }

    public function summaryData(int $tenantId, string $businessDate): array
    {
        $currencyIds = TenantAccountingTransactions::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->whereDate('business_date', '<=', $businessDate)
            ->distinct()
            ->pluck('currency_id');

        return $currencyIds->map(function ($currencyId) use ($tenantId, $businessDate): array {
            $base = TenantAccountingTransactions::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_deleted', false)
                ->when($currencyId === null, fn ($query) => $query->whereNull('currency_id'), fn ($query) => $query->where('currency_id', $currencyId));

            $opening = (clone $base)
                ->whereDate('business_date', '<', $businessDate)
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_direction = 'incoming' THEN amount WHEN transaction_direction = 'outgoing' THEN -amount ELSE 0 END), 0) AS total")
                ->value('total');
            $totals = (clone $base)
                ->whereDate('business_date', $businessDate)
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_direction = 'incoming' THEN amount ELSE 0 END), 0) AS incoming")
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_direction = 'outgoing' THEN amount ELSE 0 END), 0) AS outgoing")
                ->selectRaw("COALESCE(SUM(CASE WHEN accounting_category = 'revenue' THEN amount ELSE 0 END), 0) AS revenue")
                ->selectRaw("COALESCE(SUM(CASE WHEN accounting_category = 'expense' THEN amount ELSE 0 END), 0) AS expense")
                ->first();
            $categories = (clone $base)
                ->whereDate('business_date', $businessDate)
                ->whereNotNull('accounting_category')
                ->selectRaw('accounting_category, SUM(amount) AS total')
                ->groupBy('accounting_category')
                ->get()
                ->mapWithKeys(fn (TenantAccountingTransactions $row): array => [
                    $row->accounting_category instanceof AccountingCategory
                        ? $row->accounting_category->value
                        : (string) $row->accounting_category => (float) $row->total,
                ])
                ->all();

            $opening = (float) $opening;
            $incoming = (float) $totals->incoming;
            $outgoing = (float) $totals->outgoing;
            $revenue = (float) $totals->revenue;
            $expense = (float) $totals->expense;

            return [
                'currency_id' => $currencyId === null ? null : (int) $currencyId,
                'opening_balance' => $opening,
                'total_incoming' => $incoming,
                'total_outgoing' => $outgoing,
                'closing_balance' => $opening + $incoming - $outgoing,
                'revenue' => $revenue,
                'expense' => $expense,
                'profit' => $revenue - $expense,
                'category_totals' => $categories,
            ];
        })->all();
    }

    public function schedules(int $tenantId): Collection
    {
        return TenantAccountingDaySchedule::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('weekday')
            ->get();
    }

    public function scheduleForWeekday(int $tenantId, int $weekday): ?TenantAccountingDaySchedule
    {
        return TenantAccountingDaySchedule::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('weekday', $weekday)
            ->first();
    }

    public function upsertSchedule(int $tenantId, array $schedule): TenantAccountingDaySchedule
    {
        $day = TenantAccountingDaySchedule::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('weekday', $schedule['weekday'])
            ->first();
        $data = [
            'is_enabled' => $schedule['is_enabled'],
            'open_time' => $schedule['open_time'],
            'close_time' => $schedule['close_time'],
        ];

        if ($day === null) {
            return TenantAccountingDaySchedule::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'weekday' => $schedule['weekday'],
                ...$data,
            ]);
        }

        $day->update([...$data, 'update_key' => $day->update_key + 1]);

        return $day->refresh();
    }

    public function automationTenantIds(): Collection
    {
        $scheduled = TenantAccountingDaySchedule::query()
            ->withoutGlobalScopes()
            ->where('is_enabled', true)
            ->pluck('tenant_id');
        $open = TenantAccountingDay::query()
            ->withoutGlobalScopes()
            ->whereIn('status', ['OPEN', 'CLOSING'])
            ->pluck('tenant_id');

        return $scheduled->merge($open)->unique()->values();
    }
}
