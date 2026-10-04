<?php

namespace App\Console\Commands;

use App\Enums\AccountingDayStatus;
use App\Models\TenantAccountingTransactions;
use App\Repository\TenantAccountingDayRepository;
use App\Support\TenantScopedCacheKeys;
use App\Services\TenantModule\Accounting\TenantAccountingMonthlySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReclassifyNonCashPurchasingTransactions extends Command
{
    protected $signature = 'finance:reclassify-noncash-purchasing {--dry-run : Preview changes (default)} {--apply : Reclassify matching rows} {--tenant= : Limit to a tenant ID}';

    protected $description = 'Reclassify historical Purchasing ledger rows without account movements as internal';

    public function handle(TenantAccountingMonthlySummaryService $monthlySummaries, TenantAccountingDayRepository $dayRepository, TenantScopedCacheKeys $cacheKeys): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');
            return self::FAILURE;
        }
        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && (! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1)) {
            $this->error('--tenant must be a positive integer.');
            return self::FAILURE;
        }

        $classes = [
            'App\\Models\\PurchasingModule\\PurchaseReceiptLine',
            'App\\Models\\PurchasingModule\\SupplierPayable',
            'App\\Models\\PurchasingModule\\SupplierCreditAllocation',
            'App\\Models\\PurchasingModule\\SupplierPayableAdjustment',
            'App\\Models\\PurchasingModule\\SupplierCredit',
            'App\\Models\\PurchasingModule\\PurchaseReturnLine',
        ];
        $query = TenantAccountingTransactions::query()->withoutGlobalScopes()
            ->whereIn('reference_type', $classes)
            ->whereIn('transaction_direction', ['incoming', 'outgoing'])
            ->where('is_deleted', false)
            ->whereNotExists(function ($subquery): void {
                $subquery->selectRaw('1')->from('financial_account_transactions')
                    ->whereColumn('financial_account_transactions.related_transaction_id', 'tenant_accounting_transactions.id')
                    ->whereColumn('financial_account_transactions.tenant_id', 'tenant_accounting_transactions.tenant_id');
            });
        if ($tenantOption !== null) $query->where('tenant_id', (int) $tenantOption);

        $count = (clone $query)->count();
        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').' mode: '.$count.' row(s) match.');
        if (! $this->option('apply') || $count === 0) return self::SUCCESS;

        $affected = [];
        DB::transaction(function () use ($query, &$affected): void {
            (clone $query)->orderBy('id')->chunkById(250, function ($rows) use (&$affected): void {
                foreach ($rows as $row) {
                    $row->transaction_direction = 'internal';
                    $row->reporting_amount = null;
                    $row->exchange_rate = null;
                    $row->update_key = (int) $row->update_key + 1;
                    $row->save();
                    $date = $row->business_date?->toDateString() ?? $row->occurred_at?->toDateString();
                    if ($date) $affected[$row->tenant_id][$date] = true;
                }
            });
        });

        foreach ($affected as $tenantId => $dates) {
            foreach (array_keys($dates) as $date) {
                $monthlySummaries->summarize((int) $tenantId, CarbonImmutable::parse($date));
                $day = $dayRepository->findForTenantDate((int) $tenantId, $date);
                if ($day !== null && $day->status === AccountingDayStatus::Closed) {
                    $dayRepository->replaceSummaries($day, $dayRepository->summaryData((int) $tenantId, $date));
                }
            }
            foreach ([
                'tenant-accounting-transaction-list',
                'tenant-accounting-transaction-incoming-list',
                'tenant-accounting-transaction-outgoing-list',
                'tenant-accounting-transaction-overview',
            ] as $namespace) {
                $cacheKeys->bumpVersion($namespace, tenantId: (int) $tenantId);
            }
        }
        $this->info('Reclassified '.$count.' row(s) and refreshed affected summaries.');
        return self::SUCCESS;
    }
}
