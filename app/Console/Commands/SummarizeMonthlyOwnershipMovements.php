<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\OwnershipMonthlySummaryService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use Illuminate\Console\Command;

/** Idempotent recovery using exactly the same item publisher as scheduled execution. */
class SummarizeMonthlyOwnershipMovements extends Command
{
    protected $signature = 'ownership:summarize-monthly {--tenant= : Tenant ID} {--month= : Completed YYYY-MM month}';
    protected $description = 'Publish monthly Ownership quantity checkpoints';

    public function handle(
        OwnershipMonthlySummaryService $summaries,
        TenantSettingRepository $tenantRepository,
        TenantLookupService $tenantLookupService,
    ): int
    {
        // Validate the option format before resolving an explicit tenant through the shared lookup service.
        $tenant = $this->option('tenant');
        if ($tenant !== null && (! ctype_digit((string) $tenant) || (int) $tenant < 1)) {
            $this->error('Select an existing positive tenant ID.');
            return self::FAILURE;
        }
        try {
            if ($tenant !== null) {
                $tenantLookupService->findById((int) $tenant);
            }

            // Stream all tenants only when no explicit recovery tenant was selected.
            $tenants = $tenant === null ? $tenantRepository->streamTenantIds() : [(int) $tenant];
            $created = 0;
            foreach ($tenants as $tenantId) $created += $summaries->summarizeTenant($tenantId, $this->option('month'));
        } catch (TenantNotFound $error) {
            $this->error('Select an existing positive tenant ID.');
            return self::FAILURE;
        } catch (InvalidTenantRequest $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        $this->info("Published {$created} Ownership item checkpoint(s).");
        return self::SUCCESS;
    }
}
