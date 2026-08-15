<?php

namespace App\Repository\Accounting;

use App\Models\CoreModule\TenantSetting;
use App\Models\ReportingCurrencyRecalculation;
use App\Models\TenantAccountingTransactions;
use Illuminate\Database\Eloquent\Collection;

class ReportingCurrencyRecalculationRepository
{
    public function activeForTenant(int $tenantId, bool $lock = false): ?ReportingCurrencyRecalculation
    {
        return ReportingCurrencyRecalculation::query()
            ->withoutGlobalScopes()
            ->with(['previousReportingCurrency', 'requestedReportingCurrency'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ReportingCurrencyRecalculation::ACTIVE_STATUSES)
            ->latest('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function find(int $id, bool $lock = false): ?ReportingCurrencyRecalculation
    {
        return ReportingCurrencyRecalculation::query()
            ->withoutGlobalScopes()
            ->with(['previousReportingCurrency', 'requestedReportingCurrency'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->find($id);
    }

    public function create(array $data): ReportingCurrencyRecalculation
    {
        return ReportingCurrencyRecalculation::query()->withoutGlobalScopes()->create($data)->refresh();
    }

    public function update(ReportingCurrencyRecalculation $recalculation, array $data): ReportingCurrencyRecalculation
    {
        $recalculation->update($data);

        return $recalculation->refresh()->load(['previousReportingCurrency', 'requestedReportingCurrency']);
    }

    public function lockCurrencyPreferences(int $tenantId): TenantSetting
    {
        return $this->currencyPreferences($tenantId, true);
    }

    public function currencyPreferences(int $tenantId, bool $lock = false): TenantSetting
    {
        return TenantSetting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'currency_preferences')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    public function affectedTransactions(ReportingCurrencyRecalculation $recalculation, bool $lock = false): Collection
    {
        return TenantAccountingTransactions::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $recalculation->tenant_id)
            ->where('is_deleted', false)
            ->whereNotNull('currency_id')
            ->where('currency_id', '!=', $recalculation->requested_reporting_currency_id)
            ->where(function ($query) use ($recalculation): void {
                $query->whereBetween('business_date', [
                    $recalculation->window_start->toDateString(),
                    $recalculation->window_end->toDateString(),
                ])->orWhere(function ($query) use ($recalculation): void {
                    $query->whereNull('business_date')
                        ->whereBetween('occurred_at', [
                            $recalculation->window_start->copy()->startOfDay(),
                            $recalculation->window_end->copy()->endOfDay(),
                        ]);
                });
            })
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    public function updateTransaction(TenantAccountingTransactions $transaction, float $reportingAmount, float $exchangeRate): void
    {
        $transaction->update([
            'reporting_amount' => $reportingAmount,
            'exchange_rate' => $exchangeRate,
            'update_key' => $transaction->update_key + 1,
        ]);
    }
}
