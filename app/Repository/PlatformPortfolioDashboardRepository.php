<?php

namespace App\Repository;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformPortfolioDashboardRepository
{
    public function tenantCount(int $platformUserId): int
    {
        return DB::table('tenants')
            ->where('platform_user_id', $platformUserId)
            ->where('is_deleted', false)
            ->count();
    }

    public function activeTenantCount(int $platformUserId): int
    {
        return DB::table('tenants')
            ->where('platform_user_id', $platformUserId)
            ->where('is_deleted', false)
            ->where('status', 'active')
            ->count();
    }

    public function configuredTenantCount(int $platformUserId): int
    {
        return DB::table('tenants')
            ->where('platform_user_id', $platformUserId)
            ->where('is_deleted', false)
            ->where(function ($query): void {
                $query->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tenant_branding')
                        ->whereColumn('tenant_branding.tenant_id', 'tenants.id')
                        ->where('tenant_branding.is_deleted', false);
                })->orWhereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tenant_contacts')
                        ->whereColumn('tenant_contacts.tenant_id', 'tenants.id')
                        ->where('tenant_contacts.is_deleted', false);
                })->orWhereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tenant_settings')
                        ->whereColumn('tenant_settings.tenant_id', 'tenants.id')
                        ->where('tenant_settings.is_deleted', false);
                });
            })
            ->count();
    }

    public function expiringLicenseCount(int $platformUserId, int $days): int
    {
        return DB::table('tenants')
            ->join('tenant_licenses', 'tenant_licenses.tenant_id', '=', 'tenants.id')
            ->where('tenants.platform_user_id', $platformUserId)
            ->where('tenants.is_deleted', false)
            ->where('tenant_licenses.is_deleted', false)
            ->whereNotNull('tenant_licenses.expires_at')
            ->whereBetween('tenant_licenses.expires_at', [now(), now()->addDays($days)])
            ->count();
    }

    public function planBreakdown(int $platformUserId): array
    {
        return $this->countByColumn($platformUserId, 'tenant_licenses.plan_type', ['trial', 'basic', 'premium'], 'unknown');
    }

    public function licenseHealth(int $platformUserId): array
    {
        return $this->countByColumn(
            $platformUserId,
            'tenant_licenses.status',
            ['active', 'expired', 'pending_activation', 'suspended', 'cancelled'],
            'unknown'
        );
    }

    public function tenantPortfolioRows(int $platformUserId, Carbon $today, DashboardTimeFilter $timeFilter): Collection
    {
        $tenantRows = DB::table('tenants')
            ->leftJoin('tenant_licenses', function ($join): void {
                $join->on('tenant_licenses.tenant_id', '=', 'tenants.id')
                    ->where('tenant_licenses.is_deleted', false);
            })
            ->leftJoin('packages', function ($join): void {
                $join->on('packages.code', '=', 'tenant_licenses.plan_type')
                    ->where('packages.is_deleted', false);
            })
            ->leftJoin('tenant_contacts', function ($join): void {
                $join->on('tenant_contacts.tenant_id', '=', 'tenants.id')
                    ->where('tenant_contacts.is_deleted', false);
            })
            ->where('tenants.platform_user_id', $platformUserId)
            ->where('tenants.is_deleted', false)
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.tenant_code',
                'tenants.status as tenant_status',
                'tenant_licenses.plan_type',
                'tenant_licenses.status as license_status',
                'tenant_licenses.current_month_slip_count',
                'tenant_licenses.current_staff_count',
                'packages.max_slip_per_month',
                'packages.max_staff_count',
                'tenant_contacts.city',
                'tenant_contacts.country',
            ])
            ->orderBy('tenants.name')
            ->get();

        $tenantIds = $tenantRows->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($tenantIds === []) {
            return collect();
        }

        $todayTotals = $this->accountingTotalsByTenant(
            $tenantIds,
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay()
        );
        $monthTotals = $this->accountingTotalsByTenant(
            $tenantIds,
            $timeFilter->startDate,
            $timeFilter->endDate
        );
        $previousMonthTotals = $this->accountingTotalsByTenant(
            $tenantIds,
            $timeFilter->previousPeriodStartDate(),
            $timeFilter->previousPeriodEndDate()
        );
        $debts = $this->unpaidDebtByTenant($tenantIds);
        $activePrincipal = $this->activeLoanPrincipalByTenant($tenantIds);
        $activeCollateralMinimumRetailPrice = $this->activeCollateralMinimumRetailPriceByTenant($tenantIds);

        return $tenantRows->map(function ($tenant) use ($todayTotals, $monthTotals, $previousMonthTotals, $debts, $activePrincipal, $activeCollateralMinimumRetailPrice) {
            $today = $todayTotals->get((int) $tenant->id, $this->emptyAccountingTotals());
            $month = $monthTotals->get((int) $tenant->id, $this->emptyAccountingTotals());
            $previousMonth = $previousMonthTotals->get((int) $tenant->id, $this->emptyAccountingTotals());
            $monthNet = (float) $month['income'] - (float) $month['expense'];
            $activeCollateralRetailPrice = (float) $activeCollateralMinimumRetailPrice->get((int) $tenant->id, 0);

            return [
                'id' => (int) $tenant->id,
                'name' => $tenant->name,
                'code' => $tenant->tenant_code,
                'tenantStatus' => $tenant->tenant_status,
                'plan' => $tenant->plan_type ?? 'unknown',
                'licenseStatus' => $tenant->license_status ?? 'unknown',
                'city' => $tenant->city ?: null,
                'country' => $tenant->country ?: null,
                'todayIncome' => (float) $today['income'],
                'todayExpense' => (float) $today['expense'],
                'todayNet' => (float) $today['income'] - (float) $today['expense'],
                'todayIncomingCount' => (int) $today['incomingCount'],
                'todayOutgoingCount' => (int) $today['outgoingCount'],
                'monthIncome' => (float) $month['income'],
                'monthExpense' => (float) $month['expense'],
                'monthNet' => $monthNet,
                'activeCollateralMinimumRetailPrice' => $activeCollateralRetailPrice,
                'unrealizedNetworth' => $monthNet + $activeCollateralRetailPrice,
                'previousMonthIncome' => (float) $previousMonth['income'],
                'previousMonthExpense' => (float) $previousMonth['expense'],
                'previousMonthNet' => (float) $previousMonth['income'] - (float) $previousMonth['expense'],
                'unpaidDebt' => (float) $debts->get((int) $tenant->id, 0),
                'activePrincipal' => (float) $activePrincipal->get((int) $tenant->id, 0),
                'currentMonthSlipCount' => (int) ($tenant->current_month_slip_count ?? 0),
                'currentStaffCount' => (int) ($tenant->current_staff_count ?? 0),
                'maxSlipPerMonth' => $tenant->max_slip_per_month === null ? null : (int) $tenant->max_slip_per_month,
                'maxStaffCount' => $tenant->max_staff_count === null ? null : (int) $tenant->max_staff_count,
            ];
        });
    }

    public function streamLeaders(int $platformUserId, Carbon $startDate, Carbon $endDate, string $transactionType, int $limit = 5): array
    {

        $nameExpression = "COALESCE(
            NULLIF(tenant_accountings.reference_type, ''),
            NULLIF(tenant_accountings.description, ''),
            'Unspecified'
        )";
        return DB::table('tenant_accountings')
            ->join('tenants', 'tenants.id', '=', 'tenant_accountings.tenant_id')
            ->where('tenants.platform_user_id', $platformUserId)
            ->where('tenants.is_deleted', false)
            ->where('tenant_accountings.is_deleted', false)
            ->where('tenant_accountings.transaction_type', $transactionType)
            ->whereBetween('tenant_accountings.created_at', [$startDate, $endDate])
            ->selectRaw("$nameExpression as name")
            ->selectRaw('SUM(amount) as total')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupByRaw($nameExpression)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => class_basename((string) $row->name),
                'total' => (float) $row->total,
                'transactionCount' => (int) $row->transaction_count,
            ])
            ->all();
    }

    protected function countByColumn(int $platformUserId, string $column, array $keys, string $fallbackKey): array
    {
        $counts = DB::table('tenants')
            ->leftJoin('tenant_licenses', function ($join): void {
                $join->on('tenant_licenses.tenant_id', '=', 'tenants.id')
                    ->where('tenant_licenses.is_deleted', false);
            })
            ->where('tenants.platform_user_id', $platformUserId)
            ->where('tenants.is_deleted', false)
            ->selectRaw("COALESCE($column, ?) as grouped_value", [$fallbackKey])
            ->selectRaw('COUNT(*) as total')
            ->groupBy('grouped_value')
            ->pluck('total', 'grouped_value')
            ->map(fn ($count) => (int) $count)
            ->all();

        foreach ([...$keys, $fallbackKey] as $key) {
            $counts[$key] = $counts[$key] ?? 0;
        }

        return $counts;
    }

    protected function accountingTotalsByTenant(array $tenantIds, Carbon $startDate, Carbon $endDate): Collection
    {
        return DB::table('tenant_accountings')
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select('tenant_id')
            ->selectRaw("SUM(CASE WHEN transaction_type = 'incoming' THEN amount ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'outgoing' THEN amount ELSE 0 END) as expense")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'incoming' THEN 1 ELSE 0 END) as incoming_count")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'outgoing' THEN 1 ELSE 0 END) as outgoing_count")
            ->groupBy('tenant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->tenant_id => [
                    'income' => (float) $row->income,
                    'expense' => (float) $row->expense,
                    'incomingCount' => (int) $row->incoming_count,
                    'outgoingCount' => (int) $row->outgoing_count,
                ],
            ]);
    }

    protected function unpaidDebtByTenant(array $tenantIds): Collection
    {
        return DB::table('tenant_debts')
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_deleted', false)
            ->where('is_paid', false)
            ->select('tenant_id')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total) => (float) $total);
    }

    protected function activeLoanPrincipalByTenant(array $tenantIds): Collection
    {
        return DB::table('pawn_loan_contract_slips')
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->select('tenant_id')
            ->selectRaw('SUM(loan_amount) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total) => (float) $total);
    }

    protected function activeCollateralMinimumRetailPriceByTenant(array $tenantIds): Collection
    {
        return DB::table('pawn_collateral_items')
            ->join('pawn_loan_contract_slips', 'pawn_loan_contract_slips.id', '=', 'pawn_collateral_items.loan_contract_id')
            ->whereIn('pawn_collateral_items.tenant_id', $tenantIds)
            ->where('pawn_collateral_items.is_deleted', false)
            ->where('pawn_loan_contract_slips.is_deleted', false)
            ->whereRaw('LOWER(pawn_loan_contract_slips.status) = ?', ['active'])
            ->select('pawn_collateral_items.tenant_id')
            ->selectRaw('SUM(pawn_collateral_items.minimum_retail_price) as total')
            ->groupBy('pawn_collateral_items.tenant_id')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total) => (float) $total);
    }

    protected function emptyAccountingTotals(): array
    {
        return [
            'income' => 0.0,
            'expense' => 0.0,
            'incomingCount' => 0,
            'outgoingCount' => 0,
        ];
    }
}
