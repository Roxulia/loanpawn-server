<?php

namespace App\Console\Commands;

use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Illuminate\Console\Command;

class EnsureDefaultFinancialAccounts extends Command
{
    protected $signature = 'financial-accounts:ensure-defaults {--dry-run : Report changes without writing}';

    protected $description = 'Ensure every tenant has one active default financial account';

    public function handle(MultiAccountManagement $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->ensureDefaults($dryRun);

        $this->info($dryRun ? 'DRY-RUN mode (no writes)' : 'APPLY mode');
        $this->table(['Tenants checked', 'Accounts created', 'Accounts promoted'], [[
            $summary['tenants_checked'],
            $summary['accounts_created'],
            $summary['accounts_promoted'],
        ]]);

        return self::SUCCESS;
    }
}
