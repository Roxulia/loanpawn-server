<?php

namespace App\Console\Commands;

use App\Services\TenantModule\AccountingChangeRepairService;
use Illuminate\Console\Command;

class RepairAccountingChange extends Command
{
    protected $signature = 'accounting:repair-change
        {--dry-run : Analyze and report without writing (default)}
        {--apply : Apply safe repairs}';

    protected $description = 'Repair historical returned-change accounting inconsistencies';

    public function handle(AccountingChangeRepairService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');

        $summary = $service->repair($apply);

        $this->table(['Metric', 'Value'], [
            ['Scanned', $summary->scanned],
            ['Repaired', $summary->repaired],
            ['Already correct', $summary->alreadyCorrect],
            ['Skipped/ambiguous', $summary->skippedAmbiguous],
            ['Affected tenant IDs', implode(', ', $summary->affectedTenantIdList()) ?: 'none'],
        ]);

        if ($summary->skippedItems !== []) {
            $this->warn('Skipped records:');
            $this->table(
                ['Type', 'Tenant ID', 'Reference ID', 'Reason'],
                array_map(fn (array $item): array => [
                    $item['type'],
                    $item['tenant_id'],
                    $item['reference_id'],
                    $item['reason'],
                ], $summary->skippedItems),
            );
        }

        return self::SUCCESS;
    }
}
