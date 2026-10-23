<?php

namespace App\Repository;

use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\AcquisitionLot;
use App\Models\OwnershipModule\OwnedItem;
use App\Models\OwnershipModule\OwnershipMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection as ReadCollection;
use Illuminate\Support\Facades\DB;

class OwnershipRepository
{
    use \App\Repository\Ownership\ReadQueries;
    use \App\Repository\Ownership\StateQueries;

    /** @return ReadCollection<int, object> Latest eligible header keyed by owned-item ID. */
    public function latestCompletedCheckpoints(int $tenantId, array $ownedItemIds, string $cutoffAt): ReadCollection
    {
        // Select all requested item checkpoints together; missing items have no fabricated header.
        if ($ownedItemIds === []) {
            return collect();
        }

        return $this->latestCompletedCheckpointQuery($tenantId, $ownedItemIds, $cutoffAt)
            ->orderBy('checkpoint.owned_item_id')->get()->keyBy('owned_item_id');
    }

    /** @return ReadCollection<int, object> Saved owned/reserved/pledged quantities keyed by item ID. */
    public function openingItemBalances(int $tenantId, array $ownedItemIds, string $cutoffAt): ReadCollection
    {
        // Use only the selected header's exact stored totals, without summing covered movements.
        if ($ownedItemIds === []) {
            return collect();
        }

        return $this->openingItemBalanceQuery($tenantId, $ownedItemIds, $cutoffAt)
            ->orderBy('opening.owned_item_id')->get()->keyBy('owned_item_id');
    }

    /** @return ReadCollection<int, object> Saved quantities keyed by lot ID, scoped through their selected parent. */
    public function openingLotBalances(int $tenantId, array $ownedItemIds, string $cutoffAt): ReadCollection
    {
        // Bind children through a tenant/item-scoped header and the lot's matching aggregate.
        if ($ownedItemIds === []) {
            return collect();
        }

        return DB::table('ownership_monthly_checkpoint_balances as balance')
            ->joinSub($this->latestCompletedCheckpointQuery($tenantId, $ownedItemIds, $cutoffAt), 'checkpoint', function ($join): void {
                $join->on('checkpoint.id', '=', 'balance.ownership_monthly_checkpoint_id')
                    ->on('checkpoint.tenant_id', '=', 'balance.tenant_id');
            })
            ->join('acquisition_lots as lot', function ($join): void {
                $join->on('lot.id', '=', 'balance.acquisition_lot_id')
                    ->on('lot.tenant_id', '=', 'checkpoint.tenant_id')
                    ->on('lot.owned_item_id', '=', 'checkpoint.owned_item_id');
            })
            ->where('balance.tenant_id', $tenantId)
            ->select('checkpoint.owned_item_id', 'balance.acquisition_lot_id')
            ->selectRaw('balance.owned_quantity AS owned, balance.reserved_quantity AS reserved, balance.pledged_quantity AS pledged')
            ->orderBy('balance.acquisition_lot_id')->get()->keyBy('acquisition_lot_id');
    }

    /** @return ReadCollection<int, object> Uncovered signed deltas grouped by item and lot. */
    public function uncoveredMovementDeltas(
        int $tenantId,
        array $ownedItemIds,
        string $checkpointCutoffAt,
        ?string $recordedBefore = null,
        ?int $maximumMovementId = null,
    ): ReadCollection {
        // Optional upper bounds support a later monthly publication; unknown timestamps stay live only.
        if ($ownedItemIds === []) {
            return collect();
        }

        return $this->uncoveredMovementQuery($tenantId, $ownedItemIds, $checkpointCutoffAt, $recordedBefore, $maximumMovementId)
            ->select('movement.owned_item_id', 'movement.acquisition_lot_id')
            ->selectRaw('CAST(SUM(movement.owned_delta) AS DECIMAL(24,3)) AS owned,
                CAST(SUM(movement.reserved_delta) AS DECIMAL(24,3)) AS reserved,
                CAST(SUM(movement.pledged_delta) AS DECIMAL(24,3)) AS pledged')
            ->groupBy('movement.owned_item_id', 'movement.acquisition_lot_id')
            ->orderBy('movement.owned_item_id')->orderBy('movement.acquisition_lot_id')->get();
    }

    /** @return ReadCollection<int, array{owned: string, reserved: string, pledged: string, available: string}> */
    public function currentItemBalances(int $tenantId, array $ownedItemIds, string $checkpointCutoffAt): ReadCollection
    {
        // Keep base selection and tail coverage inside one SQL snapshot, independent of page row count.
        if ($ownedItemIds === []) {
            return collect();
        }
        $tail = $this->uncoveredMovementQuery($tenantId, $ownedItemIds, $checkpointCutoffAt)
            ->select('movement.owned_item_id')
            ->selectRaw('SUM(movement.owned_delta) AS owned, SUM(movement.reserved_delta) AS reserved, SUM(movement.pledged_delta) AS pledged')
            ->groupBy('movement.owned_item_id');
        $parts = $this->openingItemBalanceQuery($tenantId, $ownedItemIds, $checkpointCutoffAt)->unionAll($tail);
        $totals = DB::query()->fromSub($parts, 'parts')->select('owned_item_id')
            ->selectRaw('SUM(owned) AS owned, SUM(reserved) AS reserved, SUM(pledged) AS pledged')->groupBy('owned_item_id');

        // Start from real tenant-owned aggregates so zero-balance items remain present and foreign IDs do not.
        $rows = DB::table('owned_items as owner')->leftJoinSub($totals, 'totals', 'totals.owned_item_id', '=', 'owner.id')
            ->where('owner.tenant_id', $tenantId)->whereIn('owner.id', $ownedItemIds)->select('owner.id')
            ->selectRaw('CAST(COALESCE(totals.owned, 0) AS DECIMAL(24,3)) AS owned,
                CAST(COALESCE(totals.reserved, 0) AS DECIMAL(24,3)) AS reserved,
                CAST(COALESCE(totals.pledged, 0) AS DECIMAL(24,3)) AS pledged,
                CAST(COALESCE(totals.owned, 0) - COALESCE(totals.reserved, 0) - COALESCE(totals.pledged, 0) AS DECIMAL(24,3)) AS available')
            ->orderBy('owner.id')->get();

        // MySQL DECIMAL results remain strings; never clamp negatives or hide inconsistent ledger state.
        return $rows->mapWithKeys(static fn (object $row): array => [(int) $row->id => [
            'owned' => (string) $row->owned, 'reserved' => (string) $row->reserved,
            'pledged' => (string) $row->pledged, 'available' => (string) $row->available,
        ]]);
    }

    public function inventoryItem(int $tenantId, int $inventoryItemId, bool $lock = false): ?InventoryItem
    {
        $query = InventoryItem::query()->where('tenant_id', $tenantId)->whereKey($inventoryItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItems(int $tenantId, ?string $search): Collection
    {
        return OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)
            ->when($search, fn ($query) => $query->whereHas('inventoryItem', fn ($items) => $items->where('name', 'like', '%'.$search.'%')))
            ->orderByDesc('id')->get();
    }

    public function ownedItem(int $tenantId, int $ownedItemId, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)->whereKey($ownedItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItemByCode(int $tenantId, string $ownedItemCode, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->with(['inventoryItem.unit', 'catalogItem', 'acquisitionLots'])
            ->where('tenant_id', $tenantId)->where('code', $ownedItemCode);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function ownedItemForInventory(int $tenantId, int $inventoryItemId, bool $lock = false): ?OwnedItem
    {
        $query = OwnedItem::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $inventoryItemId);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function createOwnedItem(int $tenantId, array $data): OwnedItem
    {
        return OwnedItem::query()->create(['tenant_id' => $tenantId, ...$data]);
    }

    public function createLot(int $tenantId, array $data): AcquisitionLot
    {
        return AcquisitionLot::query()->create(['tenant_id' => $tenantId, ...$data]);
    }

    public function lotByCode(int $tenantId, int $ownedItemId, string $lotCode, bool $lock = false): ?AcquisitionLot
    {
        $query = AcquisitionLot::query()->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->where('code', $lotCode);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function createMovement(int $tenantId, array $data): OwnershipMovement
    {
        // Enforce append/publication exclusion for new and compatibility callers alike.
        if ($this->lockAggregate($tenantId, (int) $data['owned_item_id']) === null) {
            throw new \App\Exceptions\TenantNotFound(__('ownership.not_found'));
        }
        return OwnershipMovement::query()->create([...$data, 'tenant_id' => $tenantId]);
    }

    public function movements(int $tenantId, int $ownedItemId): Collection
    {
        return OwnershipMovement::query()->with('acquisitionLot')->where('tenant_id', $tenantId)
            ->where('owned_item_id', $ownedItemId)->orderBy('id')->get();
    }

    public function movementBalances(int $tenantId, int $ownedItemId): array
    {
        $totals = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->selectRaw('COALESCE(SUM(owned_delta), 0) as owned, COALESCE(SUM(pledged_delta), 0) as pledged, COALESCE(SUM(reserved_delta), 0) as reserved')->first();
        return ['owned' => (float) $totals->owned, 'pledged' => (float) $totals->pledged, 'reserved' => (float) $totals->reserved];
    }

    public function lotBalances(int $tenantId, int $ownedItemId, int $lotId): array
    {
        $totals = DB::table('ownership_movements')->where('tenant_id', $tenantId)
            ->where('owned_item_id', $ownedItemId)->where('acquisition_lot_id', $lotId)
            ->selectRaw('COALESCE(SUM(owned_delta), 0) as owned, COALESCE(SUM(pledged_delta), 0) as pledged, COALESCE(SUM(reserved_delta), 0) as reserved')->first();
        return ['owned' => (float) $totals->owned, 'pledged' => (float) $totals->pledged, 'reserved' => (float) $totals->reserved];
    }

    public function findBySourceCode(int $tenantId, string $module, string $type, string $sourceCode, bool $lock = false): ?AcquisitionLot
    {
        // Preserve canonical source identity across items so a mismatched item cannot hide a duplicate.
        return AcquisitionLot::query()->where('tenant_id', $tenantId)->where('source_module', $module)
            ->where('source_type', $type)->where('source_code', $sourceCode)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function latestCompletedCheckpointQuery(int $tenantId, array|Builder $ownedItemIds, string $cutoffAt): Builder
    {
        // Group eligible months in SQL rather than issuing one latest-checkpoint query per item.
        $months = DB::table('ownership_monthly_checkpoints as candidate')
            ->join('owned_items as owner', function ($join): void {
                $join->on('owner.id', '=', 'candidate.owned_item_id')->on('owner.tenant_id', '=', 'candidate.tenant_id');
            })
            ->where('candidate.tenant_id', $tenantId)->whereIn('candidate.owned_item_id', $ownedItemIds)
            ->whereNotNull('candidate.completed_at')->where('candidate.cutoff_at', '<=', $cutoffAt)
            ->select('candidate.owned_item_id')->selectRaw('MAX(candidate.month) AS month')->groupBy('candidate.owned_item_id');

        // Completion means publication is committed; its publication time need not precede the historical cutoff.
        return DB::table('ownership_monthly_checkpoints as checkpoint')
            ->joinSub($months, 'latest', function ($join): void {
                $join->on('latest.owned_item_id', '=', 'checkpoint.owned_item_id')->on('latest.month', '=', 'checkpoint.month');
            })
            ->where('checkpoint.tenant_id', $tenantId)->whereNotNull('checkpoint.completed_at')
            ->where('checkpoint.cutoff_at', '<=', $cutoffAt)->select('checkpoint.*');
    }

    private function openingItemBalanceQuery(int $tenantId, array|Builder $ownedItemIds, string $cutoffAt): Builder
    {
        // Keep the selected header as a composable SQL relation for atomic base-plus-tail reads.
        return DB::query()->fromSub($this->latestCompletedCheckpointQuery($tenantId, $ownedItemIds, $cutoffAt), 'opening')
            ->select('opening.owned_item_id')
            ->selectRaw('opening.owned_quantity AS owned, opening.reserved_quantity AS reserved, opening.pledged_quantity AS pledged');
    }

    private function uncoveredMovementQuery(
        int $tenantId,
        array|Builder $ownedItemIds,
        string $checkpointCutoffAt,
        ?string $recordedBefore = null,
        ?int $maximumMovementId = null,
    ): Builder {
        // Resolve the base in the same statement as movement coverage, never from business occurrence dates.
        $query = DB::table('ownership_movements as movement')
            ->join('owned_items as owner', function ($join): void {
                $join->on('owner.id', '=', 'movement.owned_item_id')->on('owner.tenant_id', '=', 'movement.tenant_id');
            })
            ->leftJoinSub($this->latestCompletedCheckpointQuery($tenantId, $ownedItemIds, $checkpointCutoffAt), 'checkpoint', function ($join): void {
                $join->on('checkpoint.owned_item_id', '=', 'movement.owned_item_id')->on('checkpoint.tenant_id', '=', 'movement.tenant_id');
            })
            ->where('movement.tenant_id', $tenantId)->whereIn('movement.owned_item_id', $ownedItemIds)
            ->where(function ($coverage): void {
                // A movement is covered only when both its ID and known recording time are covered.
                $coverage->whereNull('checkpoint.id')
                    ->orWhereColumn('movement.id', '>', 'checkpoint.movement_watermark')
                    ->orWhereColumn('movement.created_at', '>=', 'checkpoint.cutoff_at')
                    ->orWhereNull('movement.created_at');
            });

        // Monthly upper bounds exclude unknown recording time so it cannot silently become checkpoint-covered.
        if ($recordedBefore !== null) {
            $query->where('movement.created_at', '<', $recordedBefore);
        }
        if ($maximumMovementId !== null) {
            $query->where('movement.id', '<=', $maximumMovementId);
        }

        return $query;
    }
}
