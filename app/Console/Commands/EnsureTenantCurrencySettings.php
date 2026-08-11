<?php

namespace App\Console\Commands;

use App\Services\PlatformModule\TenantServices\TenantSettingService;
use Illuminate\Console\Command;

class EnsureTenantCurrencySettings extends Command
{
    protected $signature = 'tenant-settings:ensure-currencies {--dry-run : Report changes without writing}';

    protected $description = 'Ensure every tenant has default and reporting currency settings';

    public function handle(TenantSettingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->ensureAllTenantCurrencyPreferences($dryRun);

        $this->info($dryRun ? 'DRY-RUN mode (no writes)' : 'APPLY mode');
        $this->table(['Tenants checked', 'Created', 'Updated', 'Unchanged'], [[
            $summary['tenants_checked'],
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
        ]]);

        return self::SUCCESS;
    }
}
