<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\InventoryMonthlySummaryService;
use Illuminate\Console\Command;

/**
 * Provides an explicit, idempotent recovery entry point for missed monthly runs.
 * Uses the same Inventory summary service as the scheduled job; never runs migrations.
 */
class SummarizeMonthlyInventoryMovements extends Command
{
    protected $signature = 'inventory:summarize-monthly
        {--tenant= : Limit processing to one tenant ID}
        {--month= : Completed tenant month in YYYY-MM; defaults to the previous month}';

    protected $description = 'Build monthly physical Inventory quantity checkpoints';

    public function handle(
        InventoryMonthlySummaryService $summaries,
        TenantSettingRepository $tenantRepository,
    ): int {
        // Validate tenant selection against the existing platform tenant registry.
        $tenant = $this->option('tenant');
        if ($tenant !== null && (! ctype_digit((string) $tenant) || (int) $tenant < 1)) {
            $this->error('--tenant must be a positive integer.');

            return self::FAILURE;
        }
        $tenantIds = $tenantRepository->allTenantIds();
        if ($tenant !== null && ! $tenantIds->contains((int) $tenant)) {
            $this->error('The selected tenant does not exist.');

            return self::FAILURE;
        }
        if ($tenant !== null) {
            $tenantIds = collect([(int) $tenant]);
        }

        // Completed items are skipped on replay; failures leave earlier item commits resumable.
        try {
            $created = 0;
            foreach ($tenantIds as $tenantId) {
                $created += $summaries->summarizeTenant((int) $tenantId, $this->option('month'));
            }
        } catch (InvalidTenantRequest $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Published {$created} Inventory item checkpoint(s).");

        return self::SUCCESS;
    }
}
