<?php

namespace App\Console\Commands;

use App\Services\TenantModule\TenantLenderCodeRepairService;
use Illuminate\Console\Command;

class RepairTenantLenderCodes extends Command
{
    protected $signature = 'lenders:repair-codes
        {--tenant-id= : Limit the repair to one tenant ID}
        {--dry-run : Report legacy lender codes without writing (default)}
        {--apply : Replace legacy lender codes with generated codes}';

    protected $description = 'Replace legacy LDR lender codes using the standard tenant code generator';

    public function handle(TenantLenderCodeRepairService $tenantLenderCodeRepairService): int
    {
        // Rejection of conflicting execution modes
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        // Validation of the optional tenant boundary
        $tenantOption = $this->option('tenant-id');
        if ($tenantOption !== null && (! ctype_digit((string) $tenantOption) || (int) $tenantOption < 1)) {
            $this->error('The --tenant-id option must be a positive integer.');

            return self::INVALID;
        }

        // Execution in safe dry-run mode unless writes are explicitly requested
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');

        $summary = $tenantLenderCodeRepairService->repairLegacyCodes(
            $apply,
            $tenantOption === null ? null : (int) $tenantOption,
        );

        // Presentation of repair results
        $this->table(['Processed', 'Candidates', 'Repaired', 'Failed'], [[
            $summary['processed'],
            $summary['candidates'],
            $summary['repaired'],
            $summary['failed'],
        ]]);

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
