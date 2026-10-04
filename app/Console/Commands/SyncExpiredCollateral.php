<?php

namespace App\Console\Commands;

use App\Services\PawnModule\CollateralItemService;
use Illuminate\Console\Command;

class SyncExpiredCollateral extends Command
{
    protected $signature = 'pawn:sync-expired-collateral {--dry-run : Report matching collateral without writing (default)} {--apply : Apply the status updates} {--tenant= : Limit processing to one tenant ID}';

    protected $description = 'Expire active collateral whose pawn slip is already expired';

    public function handle(CollateralItemService $collateralItemService): int
    {
        // Require one clear execution mode when both flags are supplied.
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        // Validate the optional tenant filter before processing collateral.
        $tenantId = $this->option('tenant');
        if ($tenantId !== null && (! ctype_digit((string) $tenantId) || (int) $tenantId < 1)) {
            $this->error('--tenant must be a positive integer.');

            return self::FAILURE;
        }

        // Default to a read-only preview unless --apply was explicitly requested.
        $apply = (bool) $this->option('apply');
        $affectedCount = $collateralItemService->expireForExpiredSlips(
            $tenantId === null ? null : (int) $tenantId,
            ! $apply,
        );

        $mode = $apply ? 'Expired' : 'Would expire';
        $this->info("{$mode} {$affectedCount} active collateral item(s) whose slips are expired.");

        return self::SUCCESS;
    }
}