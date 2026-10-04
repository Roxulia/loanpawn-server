<?php

namespace App\Console\Commands;

use App\Services\PawnModule\CollateralItemService;
use Illuminate\Console\Command;

class BackfillCollateralInventory extends Command
{
    protected $signature = 'pawn:backfill-collateral-inventory {--dry-run : Preview eligible collateral without writing (default)} {--apply : Create Inventory custody items and link them} {--tenant= : Limit processing to one tenant ID; defaults to all tenants}';

    protected $description = 'Backfill Inventory custody records for legacy pawn collateral';

    public function handle(CollateralItemService $collateralItemService): int
    {
        // Reject conflicting modes so the command cannot ambiguously apply changes.
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        // Validate the optional tenant filter before reading or changing collateral.
        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && (! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1)) {
            $this->error('--tenant must be a positive integer.');

            return self::FAILURE;
        }

        // Default to a read-only preview and include every tenant unless filtered.
        $apply = (bool) $this->option('apply');
        $summary = $collateralItemService->backfillInventoryLinks(
            $tenantOption === null ? null : (int) $tenantOption,
            ! $apply,
        );

        $mode = $apply ? 'Created Inventory links for' : 'Would create Inventory links for';
        $this->info("{$mode} {$summary['scanned']} collateral item(s).");

        return self::SUCCESS;
    }
}