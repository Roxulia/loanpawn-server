<?php

namespace App\Console\Commands;

use App\Services\TenantModule\Accounting\LegacyAccountingMigrationService;
use Illuminate\Console\Command;

class MigrateLegacyAccounting extends Command
{
    protected $signature = 'accounting:migrate-legacy
        {--dry-run : Analyze and report without writing}
        {--apply : Apply the migration}';

    protected $description = 'Migrate legacy tenant accounting into the accounting and financial account ledgers';

    public function handle(LegacyAccountingMigrationService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');
        $summary = $service->migrate($apply);

        $this->table(['Currency tenants', 'Created', 'Updated to MMK', 'Unchanged'], [[
            $summary['currencySummary']['tenants_checked'],
            $summary['currencySummary']['created'],
            $summary['currencySummary']['updated'],
            $summary['currencySummary']['unchanged'],
        ]]);
        $this->table(['Account tenants', 'Accounts created', 'Accounts promoted'], [[
            $summary['accountSummary']['tenants_checked'],
            $summary['accountSummary']['accounts_created'],
            $summary['accountSummary']['accounts_promoted'],
        ]]);
        $this->table(
            ['Scanned', 'Migrated', 'Already migrated', 'Deleted', 'Internal skipped', 'No conversion', 'Failed'],
            [[...array_values($summary['totals'])]],
        );

        foreach ($summary['reports'] as $report) {
            $this->newLine();
            $this->info('Tenant '.$report['tenant_id'].' reconciliation');
            $this->table(['Ledger', 'Incoming', 'Outgoing', 'Internal', 'Balance'], [
                $this->movementRow('Old accounting', $report['old']),
                $this->movementRow('Migrated financial', $report['migrated']),
                $this->movementRow('Full default account', $report['full']),
                $this->movementRow('Old vs migrated difference', [
                    'incoming' => $report['old']['incoming'] - $report['migrated']['incoming'],
                    'outgoing' => $report['old']['outgoing'] - $report['migrated']['outgoing'],
                    'internal' => $report['old']['internal'],
                    'balance' => $report['old']['balance'] - $report['migrated']['balance'],
                ]),
            ]);
        }

        if ($summary['reports'] !== []) {
            $old = $this->sumMovement($summary['reports'], 'old');
            $migrated = $this->sumMovement($summary['reports'], 'migrated');
            $full = $this->sumMovement($summary['reports'], 'full');
            $this->newLine();
            $this->info('Overall reconciliation');
            $this->table(['Ledger', 'Incoming', 'Outgoing', 'Internal', 'Balance'], [
                $this->movementRow('Old accounting', $old),
                $this->movementRow('Migrated financial', $migrated),
                $this->movementRow('Full default accounts', $full),
                $this->movementRow('Old vs migrated difference', [
                    'incoming' => $old['incoming'] - $migrated['incoming'],
                    'outgoing' => $old['outgoing'] - $migrated['outgoing'],
                    'internal' => $old['internal'],
                    'balance' => $old['balance'] - $migrated['balance'],
                ]),
            ]);
        }

        if ($summary['failures'] !== []) {
            $this->warn('Failed records:');
            $this->table(['Tenant ID', 'Legacy ID', 'Reason'], array_map(
                fn (array $failure): array => [$failure['tenant_id'], $failure['legacy_id'] ?? '-', $failure['reason']],
                $summary['failures'],
            ));
        }

        return $summary['totals']['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function movementRow(string $label, array $movement): array
    {
        return [
            $label,
            number_format($movement['incoming'], 4, '.', ''),
            number_format($movement['outgoing'], 4, '.', ''),
            number_format($movement['internal'], 4, '.', ''),
            number_format($movement['balance'], 4, '.', ''),
        ];
    }

    private function sumMovement(array $reports, string $ledger): array
    {
        $total = ['incoming' => 0.0, 'outgoing' => 0.0, 'internal' => 0.0, 'balance' => 0.0];
        foreach ($reports as $report) {
            foreach (array_keys($total) as $metric) {
                $total[$metric] += $report[$ledger][$metric];
            }
        }

        return $total;
    }
}
