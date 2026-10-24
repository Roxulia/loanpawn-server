<?php

namespace App\Repository\Ownership;

use App\Models\OwnershipModule\OwnershipMonthlyCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Persistence only: services own closing validation and publication transactions. */
trait CheckpointQueries
{
    public function findMonthlyOwnershipCheckpointForUpdate(int $tenantId, int $ownedItemId, string $month): ?OwnershipMonthlyCheckpoint
    {
        // Current reads remain protected by the shared aggregate anchor.
        $this->requireLockTransaction();
        return OwnershipMonthlyCheckpoint::query()->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->where('month', $month)->lockForUpdate()->first();
    }

    public function createMonthlyOwnershipCheckpoint(int $tenantId, int $ownedItemId, string $month, string $timezone,
        CarbonImmutable $cutoff, int $watermark, array $balances): OwnershipMonthlyCheckpoint
    {
        // Header and children remain invisible until the caller commits their item transaction.
        $this->requireLockTransaction();
        $checkpoint = $this->insertOwnershipCheckpointHeader($tenantId, $ownedItemId, $month, $timezone, $cutoff, $watermark);
        $this->insertOwnershipCheckpointLotBalances($tenantId, (int) $checkpoint->id, $balances);
        return $checkpoint;
    }

    public function readOwnershipCheckpointBalanceFacts(int $tenantId, int $checkpointId): object
    {
        // One exact aggregate scan supplies both totals and the overcommitment fact; services decide validity.
        return DB::table('ownership_monthly_checkpoint_balances')->where('tenant_id', $tenantId)
            ->where('ownership_monthly_checkpoint_id', $checkpointId)
            ->selectRaw('COALESCE(SUM(owned_quantity), 0) AS owned_quantity,
                COALESCE(SUM(reserved_quantity), 0) AS reserved_quantity,
                COALESCE(SUM(pledged_quantity), 0) AS pledged_quantity,
                COALESCE(MAX(reserved_quantity + pledged_quantity > owned_quantity), 0) AS has_overcommitted_lots')->first();
    }

    public function saveOwnershipCheckpointTotals(OwnershipMonthlyCheckpoint $checkpoint, object $facts): OwnershipMonthlyCheckpoint
    {
        // Fixed total columns cannot overwrite tenant, item, coverage or publication metadata.
        $this->requireLockTransaction();
        $checkpoint->update(['owned_quantity' => $facts->owned_quantity,
            'reserved_quantity' => $facts->reserved_quantity, 'pledged_quantity' => $facts->pledged_quantity]);
        return $checkpoint;
    }

    public function ownedItemIdsForSummary(int $tenantId)
    {
        // Keyset batches keep PHP memory independent of the tenant's total item count.
        return DB::table('owned_items')->where('tenant_id', $tenantId)->select('id')->lazyById(200);
    }

    public function lotIdBatchesForSummary(int $tenantId, int $ownedItemId)
    {
        // Every lot, including depleted and unreceived, retains an explicit closing row.
        return DB::table('acquisition_lots')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->select('id')->lazyById(500)->chunk(500);
    }

    public function ownershipMovementWatermark(int $tenantId, int $ownedItemId): int
    {
        // Current maximum behind the shared aggregate; concurrent appends cannot pass this anchor.
        $this->requireLockTransaction();
        $last = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->orderByDesc('id')->lockForUpdate()->first(['id']);
        return (int) ($last->id ?? 0);
    }

    public function monthlyLotClosingBatch(int $tenantId, int $ownedItemId, array $lotIds, CarbonImmutable $cutoff, int $watermark)
    {
        // Exclude the new header itself. Filter the tail by batch so each lot's movements are aggregated once.
        $baseCutoff = $cutoff->subMicrosecond()->format('Y-m-d H:i:s.u');
        $opening = DB::table('ownership_monthly_checkpoint_balances as saved')
            ->joinSub($this->latestCompletedCheckpointQuery($tenantId, [$ownedItemId], $baseCutoff), 'checkpoint', function ($join): void {
                $join->on('checkpoint.id', '=', 'saved.ownership_monthly_checkpoint_id')->on('checkpoint.tenant_id', '=', 'saved.tenant_id');
            })->where('saved.tenant_id', $tenantId)->whereIn('saved.acquisition_lot_id', $lotIds)
            ->select('saved.acquisition_lot_id', 'saved.owned_quantity', 'saved.reserved_quantity', 'saved.pledged_quantity');
        $tail = $this->uncoveredMovementQuery($tenantId, [$ownedItemId], $baseCutoff, $cutoff->format('Y-m-d H:i:s.u'), $watermark)
            ->whereIn('movement.acquisition_lot_id', $lotIds)->select('movement.acquisition_lot_id')
            ->selectRaw('SUM(movement.owned_delta) AS owned, SUM(movement.reserved_delta) AS reserved, SUM(movement.pledged_delta) AS pledged')
            ->groupBy('movement.acquisition_lot_id');
        return DB::table('acquisition_lots as lot')->where('lot.tenant_id', $tenantId)->where('lot.owned_item_id', $ownedItemId)->whereIn('lot.id', $lotIds)
            ->leftJoinSub($opening, 'opening', 'opening.acquisition_lot_id', '=', 'lot.id')
            ->leftJoinSub($tail, 'tail', 'tail.acquisition_lot_id', '=', 'lot.id')->select('lot.id as acquisition_lot_id')
            ->selectRaw('CAST(COALESCE(opening.owned_quantity, 0) + COALESCE(tail.owned, 0) AS DECIMAL(24,3)) AS owned_quantity,
                CAST(COALESCE(opening.reserved_quantity, 0) + COALESCE(tail.reserved, 0) AS DECIMAL(24,3)) AS reserved_quantity,
                CAST(COALESCE(opening.pledged_quantity, 0) + COALESCE(tail.pledged, 0) AS DECIMAL(24,3)) AS pledged_quantity')
            ->orderBy('lot.id')->get();
    }

    public function appendCheckpointClosingBatch(int $tenantId, int $checkpointId, array $balances): void
    {
        // Reuse fixed-column bounded child persistence for supplied and calculated checkpoint rows.
        $this->requireLockTransaction();
        $this->insertOwnershipCheckpointLotBalances($tenantId, $checkpointId, $balances);
    }

    private function insertOwnershipCheckpointHeader(int $tenantId, int $ownedItemId, string $month, string $timezone,
        CarbonImmutable $cutoff, int $watermark): OwnershipMonthlyCheckpoint
    {
        // Initial totals are replaced inside the same transaction after the exact child aggregate is validated.
        return OwnershipMonthlyCheckpoint::query()->create([
            'tenant_id' => $tenantId, 'owned_item_id' => $ownedItemId, 'month' => $month,
            'timezone' => $timezone, 'cutoff_at' => $cutoff, 'movement_watermark' => $watermark,
            'completed_at' => CarbonImmutable::now('UTC'), 'owned_quantity' => '0.000',
            'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000',
        ]);
    }

    private function insertOwnershipCheckpointLotBalances(int $tenantId, int $checkpointId, array $balances): void
    {
        // Bound insert size and retain authoritative parent/tenant references on every child.
        foreach (array_chunk($balances, 500) as $chunk) {
            $rows = array_map(fn ($row) => ['tenant_id' => $tenantId, 'ownership_monthly_checkpoint_id' => $checkpointId,
                'acquisition_lot_id' => $row['acquisition_lot_id'], 'owned_quantity' => $row['owned_quantity'],
                'reserved_quantity' => $row['reserved_quantity'], 'pledged_quantity' => $row['pledged_quantity']], $chunk);
            DB::table('ownership_monthly_checkpoint_balances')->insert($rows);
        }
    }
}
