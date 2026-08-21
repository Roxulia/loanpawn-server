<?php

namespace App\Console\Commands;

use App\Services\TenantModule\CustomerTrustScoreService;
use Illuminate\Console\Command;

class BackfillCustomerTrustScores extends Command
{
    protected $signature = 'customers:backfill-trust-scores
        {--dry-run : Calculate and report without writing (default)}
        {--apply : Apply the recalculated trust scores}';

    protected $description = 'Recalculate trust scores for all active tenant customers';

    public function handle(CustomerTrustScoreService $customerTrustScoreService): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');

        $summary = $customerTrustScoreService->backfillAll($apply);

        $this->table(['Processed', 'Changed', 'Unchanged', 'Failed'], [[
            $summary['processed'],
            $summary['changed'],
            $summary['unchanged'],
            $summary['failed'],
        ]]);

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
