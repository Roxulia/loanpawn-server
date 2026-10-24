<?php

namespace App\Services\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\OwnershipRepository;
use App\Support\{OwnershipQuantity, TenantContext};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Per-item atomic checkpoints, O(batch) PHP memory, previous-base plus uncovered-tail SQL work. */
class OwnershipMonthlySummaryService
{
    public function __construct(private OwnershipRepository $repository, private AccountingDayBusinessClock $businessClock,
        private Container $container) {}

    public function summarizeTenant(int $tenantId, ?string $month = null): int
    {
        // A new snapshot must start after each item anchor; never join an unrelated business transaction.
        if (DB::connection()->transactionLevel() !== 0) throw new LogicException('Ownership summarization must start outside a business transaction.');
        $timezone = $this->businessClock->timezone($tenantId);
        $period = $this->resolveCompletedOwnershipMonth($month, $timezone);
        $original = $this->container->make(TenantContext::class);
        $context = new TenantContext();
        $context->set($tenantId);
        $this->container->instance(TenantContext::class, $context);
        try {
            $created = 0;
            foreach ($this->repository->ownedItemIdsForSummary($tenantId) as $item) {
                $created += $this->publishItemMonth($tenantId, (int) $item->id, $period, $timezone) ? 1 : 0;
            }
            return $created;
        } finally {
            // Preserve the original context object and its exact prior value on success and failure.
            $this->container->instance(TenantContext::class, $original);
        }
    }

    public function completedMonthForTenant(int $tenantId, ?string $month = null): string
    {
        // Jobs resolve the tenant-local period before dispatch so delayed retries remain pinned.
        return $this->resolveCompletedOwnershipMonth($month, $this->businessClock->timezone($tenantId))->format('Y-m');
    }

    private function resolveCompletedOwnershipMonth(?string $month, string $timezone): CarbonImmutable
    {
        // Strict syntax prevents date normalization and partial/current-month publication.
        if ($month !== null && ! preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $month)) {
            throw new InvalidTenantRequest(__('ownership.summary_month_invalid'));
        }
        $period = $month === null ? CarbonImmutable::now($timezone)->startOfMonth()->subMonth()
            : CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', $timezone);
        if ($period->addMonth()->utc()->isAfter(CarbonImmutable::now('UTC'))) {
            throw new InvalidTenantRequest(__('ownership.summary_month_incomplete'));
        }
        return $period;
    }

    private function publishItemMonth(int $tenantId, int $ownedItemId, CarbonImmutable $period, string $timezone): bool
    {
        // Aggregate-only publication never takes Inventory/source locks after its anchor.
        return DB::transaction(function () use ($tenantId, $ownedItemId, $period, $timezone): bool {
            if ($this->repository->lockAggregate($tenantId, $ownedItemId) === null) return false;
            if ($this->repository->findMonthlyOwnershipCheckpointForUpdate($tenantId, $ownedItemId, $period->toDateString()) !== null) return false;
            $cutoff = $period->addMonth()->utc();
            $watermark = $this->repository->ownershipMovementWatermark($tenantId, $ownedItemId);
            $checkpoint = $this->repository->createMonthlyOwnershipCheckpoint($tenantId, $ownedItemId,
                $period->toDateString(), $timezone, $cutoff, $watermark, []);
            $this->writeItemClosingBatches($tenantId, $ownedItemId, (int) $checkpoint->id, $cutoff, $watermark);
            $facts = $this->repository->readOwnershipCheckpointBalanceFacts($tenantId, (int) $checkpoint->id);
            if ($facts->has_overcommitted_lots) throw new InvalidTenantRequest(__('ownership.summary_inconsistent'));
            $this->repository->saveOwnershipCheckpointTotals($checkpoint, $facts);
            return true;
        });
    }

    private function writeItemClosingBatches(int $tenantId, int $ownedItemId, int $checkpointId, CarbonImmutable $cutoff, int $watermark): void
    {
        // At most 500 lots/closings reside in PHP; no full retained movement collection is materialized.
        foreach ($this->repository->lotIdBatchesForSummary($tenantId, $ownedItemId) as $batch) {
            $rows = $this->repository->monthlyLotClosingBatch($tenantId, $ownedItemId, $batch->pluck('id')->all(), $cutoff, $watermark);
            $balances = [];
            foreach ($rows as $row) {
                $balance = ['acquisition_lot_id' => $row->acquisition_lot_id];
                foreach (['owned_quantity', 'reserved_quantity', 'pledged_quantity'] as $field) {
                    $balance[$field] = OwnershipQuantity::normalizeBalance($row->$field);
                }
                $balances[] = $balance;
            }
            $this->repository->appendCheckpointClosingBatch($tenantId, $checkpointId, $balances);
        }
    }
}
