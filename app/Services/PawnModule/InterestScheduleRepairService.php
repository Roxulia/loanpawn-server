<?php

namespace App\Services\PawnModule;

use App\DataObjects\ResponseObjects\InterestScheduleRepairSummary;
use App\Exceptions\InvalidTenantRequest;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\InterestScheduleRepairRepository;
use App\Services\TenantModule\TenantAuditLogService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
    ) {}

    public function repair(bool $apply, ?int $tenantId = null, ?string $slipNo = null): InterestScheduleRepairSummary
    {
        $summary = new InterestScheduleRepairSummary();
        $summary->skippedByStatus = $this->repository->countNonActiveSlips($tenantId, $slipNo);

        $this->repository->chunkActiveSlips(self::CHUNK_SIZE, function ($slips) use ($summary, $apply): void {
            foreach ($slips as $slip) {
                $summary->scanned++;
                $latestPayment = $this->repository->latestPaidPayment((int) $slip->id, (int) $slip->tenant_id);
                if ($latestPayment === null) {
                    $summary->skippedWithoutPayment++;
                    continue;
                }

                try {
                    if ($this->scheduleIsCorrect($slip, $latestPayment)) {
                        $summary->alreadyCorrect++;
                        continue;
                    }

                    if ($apply) {
                        if (! $this->repairSlip((int) $slip->id, (int) $slip->tenant_id)) {
                            $summary->alreadyCorrect++;
                            continue;
                        }
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
                $latestPayment = $this->repository->latestPaidPayment($slipId, $tenantId, true)
                    ?? throw new InvalidTenantRequest('No paid interest row with a payment date was found.');
                $paymentDate = CarbonImmutable::parse($latestPayment->payment_at)->startOfDay();
                $window = $this->interestFlowService->calculateRenewalWindow($slip, $paymentDate);
                $futureRows = $this->repository->unpaidAfterPayment($slipId, $tenantId, $paymentDate->toDateString(), true);

                if ($this->matchesExpectedSchedule($slip, $futureRows, $window['start_at'], $window['expire_at'])) {
                    return false;
                }

                $oldExpireAt = $slip->expire_at?->toDateString();
                $deletedCount = $this->repository->deletePayments($futureRows);
                $updatedSlip = $this->repository->updateSlip($slip, [
                    'expire_at' => $window['expire_at'],
                    'last_interest_paid_at' => $latestPayment->payment_at,
                    'last_interest_added_at' => $latestPayment->payment_at,
                    'update_key' => (int) $slip->update_key + 1,
                ]);
                $expectedRows = $this->interestFlowService->expectedScheduleRows($updatedSlip, $window['start_at'], $window['expire_at']);
                $this->interestFlowService->recreateRenewedSchedule($updatedSlip, $window['start_at'], $window['expire_at']);

                $this->tenantAuditLogService->log(
                    'pawn_interest_schedule.repaired',
                    PawnLoanContractSlip::class,
                    (int) $updatedSlip->id,
                    [
                        'payment_at' => $paymentDate->toDateString(),
                        'old_expire_at' => $oldExpireAt,
                        'new_start_at' => $window['start_at']->toDateString(),
                        'new_expire_at' => $window['expire_at']->toDateString(),
                        'deleted_row_count' => $deletedCount,
                        'created_row_count' => count($expectedRows),
                    ],
                );

                return true;
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function scheduleIsCorrect(PawnLoanContractSlip $slip, PawnInterestPayment $latestPayment): bool
    {
        $paymentDate = CarbonImmutable::parse($latestPayment->payment_at)->startOfDay();
        $window = $this->interestFlowService->calculateRenewalWindow($slip, $paymentDate);
        $futureRows = $this->repository->unpaidAfterPayment((int) $slip->id, (int) $slip->tenant_id, $paymentDate->toDateString());

        return $this->matchesExpectedSchedule($slip, $futureRows, $window['start_at'], $window['expire_at']);
    }

    private function matchesExpectedSchedule(
        PawnLoanContractSlip $slip,
        Collection $actualRows,
        CarbonImmutable $startAt,
        CarbonImmutable $expireAt,
    ): bool {
        if ($slip->expire_at === null || ! CarbonImmutable::parse($slip->expire_at)->startOfDay()->equalTo($expireAt)) {
            return false;
        }

        $expectedRows = $this->interestFlowService->expectedScheduleRows($slip, $startAt, $expireAt);
        if ($actualRows->count() !== count($expectedRows)) {
            return false;
        }

        foreach ($actualRows->values() as $index => $actual) {
            $expected = $expectedRows[$index];
            if (
                ! CarbonImmutable::parse($actual->start_period_at)->startOfDay()->equalTo($expected['start_period_at'])
                || ! CarbonImmutable::parse($actual->end_period_at)->startOfDay()->equalTo($expected['end_period_at'])
                || abs((float) $actual->calculated_interest - $expected['calculated_interest']) > 0.0001
                || (int) $actual->created_account_id !== (int) $slip->account_id
            ) {
                return false;
            }
        }

        return true;
    }
}
