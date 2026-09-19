<?php

namespace App\Services\PawnModule;

use App\DataObjects\ResponseObjects\InterestScheduleRepairSummary;
use App\Exceptions\InvalidTenantRequest;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\InterestScheduleRepairRepository;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Services\TenantModule\TenantAuditLogService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

class InterestScheduleRepairService
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private InterestScheduleRepairRepository $repository,
        private InterestFlowService $interestFlowService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantContext $tenantContext,
        private AccountingDayBusinessClock $businessClock,
    ) {}

    public function repair(bool $apply, ?int $tenantId = null, ?string $slipNo = null): InterestScheduleRepairSummary
    {
        $summary = new InterestScheduleRepairSummary();
        $summary->skippedByStatus = $this->repository->countNonActiveSlips($tenantId, $slipNo);

        $this->repository->chunkActiveSlips(self::CHUNK_SIZE, function ($slips) use ($summary, $apply): void {
            foreach ($slips as $slip) {
                $summary->scanned++;

                try {
                    if ($apply) {
                        // Apply cleanup and due-row recovery together for the selected slip.
                        if (! $this->repairSlip((int) $slip->id, (int) $slip->tenant_id)) {
                            $summary->alreadyCorrect++;
                            continue;
                        }
                    } elseif ($this->scheduleIsCorrect($slip)) {
                        $summary->alreadyCorrect++;
                        continue;
                    }
                    $summary->repaired++;
                } catch (Throwable $exception) {
                    $summary->recordFailure((int) $slip->tenant_id, (int) $slip->id, (string) $slip->slip_no, $exception->getMessage());
                }
            }
        }, $tenantId, $slipNo);

        return $summary;
    }

    private function repairSlip(int $slipId, int $tenantId): bool
    {
        $this->tenantContext->set($tenantId);

        try {
            return DB::transaction(function () use ($slipId, $tenantId): bool {
                $slip = $this->repository->lockActiveSlip($slipId, $tenantId)
                    ?? throw new InvalidTenantRequest('Active loan contract slip was not found.');
                $tenantNow = $this->businessClock->now($tenantId);
                // Remove only unpaid rows that have not started in the tenant's current day.
                $futureRows = $this->repository->unpaidStartingAfter(
                    $slipId,
                    $tenantId,
                    $tenantNow->endOfDay(),
                    true,
                );
                $deletedCount = $this->repository->deletePayments($futureRows);
                // Restore only rows due through today under the incremental rules.
                $createdCount = $this->interestFlowService->materializeDueInterestRows($slip, $tenantNow);

                if ($deletedCount === 0 && $createdCount === 0) {
                    return false;
                }

                $this->tenantAuditLogService->log(
                    'pawn_interest_schedule.repaired',
                    PawnLoanContractSlip::class,
                    (int) $slip->id,
                    [
                        'through_date' => $tenantNow->toDateString(),
                        'deleted_row_count' => $deletedCount,
                        'created_row_count' => $createdCount,
                    ],
                );

                return true;
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function scheduleIsCorrect(PawnLoanContractSlip $slip): bool
    {
        // A valid incremental schedule must not contain unpaid future rows.
        return $this->repository->unpaidStartingAfter(
            (int) $slip->id,
            (int) $slip->tenant_id,
            $this->businessClock->now((int) $slip->tenant_id)->endOfDay(),
        )->isEmpty();
    }
}
