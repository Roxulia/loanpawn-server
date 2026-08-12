<?php

namespace App\Console\Commands;

use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use Illuminate\Console\Command;

class ReconcileFinancialAccountBalances extends Command
{
    protected $signature = 'finance:reconcile-account-balances {--tenant= : Reconcile only one tenant ID} {--dry-run : Report changes without writing them}';

    protected $description = 'Reconcile stored financial account balances from the immutable transaction ledger';

    public function handle(FinancialAccountTransactionService $service): int
    {
        $tenantId = $this->option('tenant') === null ? null : (int) $this->option('tenant');
        $summary = $service->reconcile($tenantId, (bool) $this->option('dry-run'));
        $this->table(['Account', 'Tenant', 'Before', 'After'], array_map(fn (array $row): array => [$row['id'], $row['tenant_id'], $row['before'], $row['after']], $summary['accounts']));
        $this->info("Checked {$summary['checked']} accounts; {$summary['changed']} require changes.");

        return self::SUCCESS;
    }
}
