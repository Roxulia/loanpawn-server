<?php

namespace App\Repository;

use App\Models\CoreModule\TenantScheduledExpense;
use App\Models\CoreModule\TenantScheduledExpenseOccurrence;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TenantScheduledExpenseRepository
{
    private function relations(): array { return ['account.currency', 'expenseType', 'creator']; }

    public function paginate(int $perPage, ?string $search): LengthAwarePaginator
    {
        return TenantScheduledExpense::query()->with($this->relations())
            ->withCount(['occurrences as pending_count' => fn ($query) => $query->whereIn('status', ['pending', 'failed'])])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
            ->orderByDesc('id')->paginate($perPage);
    }

    public function findByCode(string $code, bool $lock = false): ?TenantScheduledExpense
    {
        $query = TenantScheduledExpense::query()->with($this->relations())
            ->withCount(['occurrences as pending_count' => fn ($query) => $query->whereIn('status', ['pending', 'failed'])])
            ->where('code', $code);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function paginateOccurrences(TenantScheduledExpense $schedule, int $perPage): LengthAwarePaginator
    {
        return TenantScheduledExpenseOccurrence::query()
            ->with(['account.currency', 'expenseType', 'expense'])
            ->where('scheduled_expense_id', $schedule->id)
            ->orderByDesc('due_date')->orderByDesc('due_time')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantScheduledExpense { return TenantScheduledExpense::query()->create($data)->load($this->relations()); }
    public function update(TenantScheduledExpense $schedule, array $data): TenantScheduledExpense { $schedule->update($data); return $schedule->refresh()->load($this->relations()); }

    public function activeTenantIds(): Collection
    {
        // Include tenants whose final recurrence was already materialized but
        // still has an unpaid occurrence waiting for an accounting-day window.
        $scheduleTenants = TenantScheduledExpense::query()->withoutGlobalScope('tenant')->where('status', 'active')->whereNotNull('next_due_date')->pluck('tenant_id');
        $pendingTenants = TenantScheduledExpenseOccurrence::query()->withoutGlobalScope('tenant')->whereIn('status', ['pending', 'failed'])
            ->whereHas('schedule', fn ($query) => $query->where('status', '!=', 'paused'))->pluck('tenant_id');
        return $scheduleTenants->merge($pendingTenants)->unique()->sort()->values();
    }

    public function dueSchedules(int $tenantId, string $localDate, int $limit): Collection
    {
        return TenantScheduledExpense::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereNotNull('next_due_date')->whereDate('next_due_date', '<=', $localDate)->orderBy('next_due_date')->limit($limit)->get();
    }

    public function createOccurrence(array $identity, array $data): TenantScheduledExpenseOccurrence
    {
        return TenantScheduledExpenseOccurrence::query()->firstOrCreate($identity, $data);
    }

    public function pendingOccurrences(int $tenantId, CarbonInterface $now, int $limit): Collection
    {
        return TenantScheduledExpenseOccurrence::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'failed'])->whereDate('due_date', '<=', $now->toDateString())
            ->whereHas('schedule', fn ($query) => $query->where('status', '!=', 'paused'))
            ->orderBy('due_date')->orderBy('due_time')->limit($limit)->get();
    }

    public function lockOccurrence(int $id): ?TenantScheduledExpenseOccurrence
    {
        return TenantScheduledExpenseOccurrence::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function updateOccurrence(TenantScheduledExpenseOccurrence $occurrence, array $data): TenantScheduledExpenseOccurrence
    {
        $occurrence->update($data); return $occurrence->refresh();
    }

    public function cancelPending(TenantScheduledExpense $schedule): void
    {
        $schedule->occurrences()->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
    }

    public function pendingCount(TenantScheduledExpense $schedule): int
    {
        return $schedule->occurrences()->whereIn('status', ['pending', 'failed'])->count();
    }

    public function softDelete(TenantScheduledExpense $schedule): void { $schedule->delete(); }
}
