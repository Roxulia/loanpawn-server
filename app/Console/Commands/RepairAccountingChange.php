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

        $this->table(['Category', 'Scanned', 'Repaired', 'Already correct', 'Skipped/ambiguous'], [
            $this->categoryRow('Interest payments', $summary->byType['interest']),
            $this->categoryRow('Debt payments', $summary->byType['debt']),
            $this->categoryRow('Redemptions', $summary->byType['redemption']),
            ['Total', $summary->scanned, $summary->repaired, $summary->alreadyCorrect, $summary->skippedAmbiguous],
        ]);

        $this->info('Affected tenant IDs: '.(implode(', ', $summary->affectedTenantIdList()) ?: 'none'));

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

    /**
     * @param array{scanned: int, repaired: int, already_correct: int, skipped: int} $metrics
     * @return array{string, int, int, int, int}
     */
    private function categoryRow(string $label, array $metrics): array
    {
        return [
            $label,
            $metrics['scanned'],
            $metrics['repaired'],
            $metrics['already_correct'],
            $metrics['skipped'],
        ];
    }
}
