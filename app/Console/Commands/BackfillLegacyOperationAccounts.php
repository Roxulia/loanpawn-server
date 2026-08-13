<?php

namespace App\Console\Commands;

use App\Services\TenantModule\Accounting\LegacyOperationAccountBackfillService;
use Illuminate\Console\Command;

class BackfillLegacyOperationAccounts extends Command
{
    protected $signature = 'accounting:backfill-operation-accounts
        {--dry-run : Analyze and report without writing (default)}
        {--apply : Apply the backfill}';

    protected $description = 'Populate missing financial account references on legacy pawn and debt records';

    public function handle(LegacyOperationAccountBackfillService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');
        $summary = $service->backfill($apply);
        $this->renderSummary($summary);

        return $summary['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSummary(array $summary): void
    {
        $this->table(['Tenants checked', 'Defaults created', 'Defaults promoted', 'Fields populated'], [[
            $summary['tenants_checked'], $summary['accounts_created'], $summary['accounts_promoted'], $summary['total'],
        ]]);
        $this->table(['Field', 'Records'], [
            ['Loan contract account', $summary['fields']['loan_contract_account']],
            ['Interest creation account', $summary['fields']['interest_created_account']],
            ['Paid interest acceptance account', $summary['fields']['interest_accept_account']],
            ['Redemption account', $summary['fields']['redemption_account']],
            ['Debt creation account', $summary['fields']['debt_created_account']],
            ['Paid debt acceptance account', $summary['fields']['debt_accept_account']],
        ]);

        if ($summary['failures'] !== []) {
            $this->warn('Failed tenants:');
            $this->table(['Tenant ID', 'Reason'], array_map(
                fn (array $failure): array => [$failure['tenant_id'], $failure['reason']],
                $summary['failures'],
            ));
        }
    }
}
