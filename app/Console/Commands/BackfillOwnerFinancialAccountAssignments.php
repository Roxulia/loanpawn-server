<?php

namespace App\Console\Commands;

use App\Services\TenantModule\Accounting\FinancialAccountAssignmentService;
use Illuminate\Console\Command;

class BackfillOwnerFinancialAccountAssignments extends Command
{
    protected $signature = 'financial-accounts:backfill-owner-assignments
        {--tenant-id= : Limit the backfill to one tenant ID}
        {--dry-run : Report missing assignments without writing}';

    protected $description = 'Assign every tenant owner to all existing non-deleted financial accounts';

    public function handle(FinancialAccountAssignmentService $service): int
    {
        $tenantOption = $this->option('tenant-id');
        if ($tenantOption !== null && (! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1)) {
            $this->error('The --tenant-id option must be a positive integer.');
            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->backfillOwners($tenantOption === null ? null : (int) $tenantOption, $dryRun);

        $this->info($dryRun ? 'DRY-RUN mode (no writes)' : 'APPLY mode');
        $this->table(['Tenants checked', 'Accounts checked', 'Assignments created'], [[
            $summary['tenants_checked'],
            $summary['accounts_checked'],
            $summary['assignments_created'],
        ]]);

        return self::SUCCESS;
    }
}
