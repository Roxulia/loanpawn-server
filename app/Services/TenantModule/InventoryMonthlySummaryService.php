<?php

namespace App\Services\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\InventoryRepository;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Builds completed tenant-month physical quantity checkpoints one item at a time.
 * Reuses the existing business clock, restores TenantContext after background work,
 * and publishes each item's closing balances atomically under its stock anchor lock.
 */
class InventoryMonthlySummaryService
{
    public function __construct(
        private InventoryRepository $repository,
        private AccountingDayBusinessClock $businessClock,
    ) {
        // Inventory owns its checkpoint persistence; the existing clock supplies timezone only.
    }

    /** Summarize an explicit YYYY-MM or the tenant's previous completed month. */
    public function summarizeTenant(int $tenantId, ?string $month = null): int
    {
        // A fresh transaction snapshot must begin after each item's anchor is acquired.
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Inventory summarization must start outside a business transaction.');
        }
        $timezone = $this->businessClock->timezone($tenantId);
        $period = $this->completedMonth($month, $timezone);
        $cutoff = $period->addMonth()->startOfMonth()->utc();

        // Background workers must establish tenant context and restore the previous binding.
        $originalContext = app(TenantContext::class);
        $summaryContext = new TenantContext();
        $summaryContext->set($tenantId);
        app()->instance(TenantContext::class, $summaryContext);
        $created = 0;

        try {
            foreach ($this->repository->inventoryItemIdsForSummary() as $item) {
                // Keep locks short and retries resumable by committing one complete item at a time.
                $published = DB::transaction(function () use ($item, $period, $timezone, $cutoff): bool {
                    $lockedItem = $this->repository->lockItemForSummary((int) $item->id);
                    if ($lockedItem === null
                        || $this->repository->monthlyCheckpoint((int) $item->id, $period->toDateString()) !== null) {
                        return false;
                    }

                    // Reuse the previous base plus uncovered recorded movements before the cutoff.
                    $balances = $this->repository->movementBalancesForItems([(int) $item->id], null, $cutoff);
                    $watermark = $this->repository->movementWatermark((int) $item->id);
                    $this->repository->createMonthlyCheckpoint(
                        (int) $item->id,
                        $period->toDateString(),
                        $timezone,
                        $cutoff,
                        $watermark,
                        $balances,
                    );

                    return true;
                });
                $created += $published ? 1 : 0;
            }
        } finally {
            // Never leak one tenant's scope into the next scheduled task or request.
            app()->instance(TenantContext::class, $originalContext);
        }

        return $created;
    }

    /** Pin a tenant-local month before queue dispatch so delayed retries retain their period. */
    public function completedMonthForTenant(int $tenantId, ?string $month = null): string
    {
        // Reuse the same validation and timezone policy as checkpoint publication.
        return $this->completedMonth($month, $this->businessClock->timezone($tenantId))->format('Y-m');
    }

    /** Resolve a valid completed calendar month using the tenant's configured timezone. */
    private function completedMonth(?string $month, string $timezone): CarbonImmutable
    {
        // Strict syntax avoids Carbon normalizing invalid dates into a different month.
        if ($month !== null && ! preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $month)) {
            throw new InvalidTenantRequest(__('inventory.summary_month_invalid'));
        }
        $period = $month === null
            ? CarbonImmutable::now($timezone)->startOfMonth()->subMonth()
            : CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', $timezone);

        // Current and future months cannot be published as closing balances.
        if ($period->addMonth()->utc()->isAfter(CarbonImmutable::now('UTC'))) {
            throw new InvalidTenantRequest(__('inventory.summary_month_incomplete'));
        }

        return $period;
    }
}
