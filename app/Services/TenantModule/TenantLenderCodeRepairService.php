<?php

namespace App\Services\TenantModule;

use App\Repository\TenantLenderRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantLenderCodeRepairService extends BaseTenantService
{
    public function __construct(
        private TenantLenderRepository $repository,
        private TableIdGenerationService $tableIdGenerationService,
    ) {
    }

    /**
     * @return array{processed: int, candidates: int, repaired: int, failed: int}
     */
    public function repairLegacyCodes(bool $apply, ?int $tenantId = null): array
    {
        // Initialization of command result counters
        $summary = [
            'processed' => 0,
            'candidates' => 0,
            'repaired' => 0,
            'failed' => 0,
        ];

        // Processing of only lender rows created with the legacy migration format
        foreach ($this->repository->legacyCodeLenders($tenantId) as $lender) {
            $summary['processed']++;
            $summary['candidates']++;

            if (! $apply) {
                continue;
            }

            try {
                // Atomic generation and assignment using the standard tenant table-code sequence
                DB::transaction(function () use ($lender): void {
                    $generatedCode = $this->tableIdGenerationService->generateForTenant(
                        (int) $lender->tenant_id,
                        'tenant_lenders',
                        CarbonImmutable::instance($lender->created_at),
                    );

                    $this->repository->updateCode($lender, $generatedCode);
                });

                $summary['repaired']++;
            } catch (Throwable) {
                // Recording of unsuccessful writes without stopping remaining tenants
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
