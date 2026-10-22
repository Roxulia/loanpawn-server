<?php

namespace App\Repository\Ownership;

use App\DTOs\Ownership\Public\RequestObjects\{OwnershipListFilter, OwnershipLotFilter, OwnershipMovementFilter};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Composable, tenant-bound read queries owned by OwnershipRepository. */
trait ReadQueries
{
    public function currentLotBalances(int $tenantId, array $itemIds, string $cutoff): Collection
    {
        // One SQL snapshot combines the selected closing and uncovered movements for every requested lot.
        if ($itemIds === []) return collect();
        return $this->lotBalanceQuery($tenantId, $itemIds, $cutoff)->orderBy('lot.id')->get()->keyBy('id');
    }

    public function itemPage(int $tenantId, OwnershipListFilter $filter, string $cutoff, ?string $ownedCode = null)
    {
        // Filter on exact current balances in SQL before counting/paginating, including zero/depleted rows.
        $query = DB::table('owned_items as owner')->join('inventory_items as item', function ($join): void {
            $join->on('item.id', '=', 'owner.inventory_item_id')->on('item.tenant_id', '=', 'owner.tenant_id');
        })->leftJoin('catalog_units as unit', 'unit.id', '=', 'item.unit_id')
            ->leftJoin('catalog_items as catalog', 'catalog.id', '=', 'owner.catalog_item_id')
            ->leftJoinSub($this->itemTotalsQuery($tenantId, null, $cutoff), 'balance', 'balance.owned_item_id', '=', 'owner.id')
            ->where('owner.tenant_id', $tenantId)
            ->when($ownedCode !== null, fn ($q) => $q->where('owner.code', $ownedCode))
            ->when($filter->search !== null, fn ($q) => $q->where('item.name', 'like', '%'.$filter->search.'%'))
            ->when($filter->trackingMode !== null, fn ($q) => $q->where('item.tracking_mode', $filter->trackingMode));
        $this->filterHolding($query, $filter->holding, 'COALESCE(balance.owned, 0)');
        return $query->select('owner.*', 'item.code as inventory_item_code', 'item.name', 'item.description',
            'item.tracking_mode', 'item.quantity_scale', 'unit.code as unit_code', 'unit.name as unit_name', 'catalog.business_code as catalog_item_code')
            ->selectRaw($this->balanceColumns('balance'))->orderBy('item.name')->orderBy('owner.id')
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function lotPage(int $tenantId, int $itemId, OwnershipLotFilter $filter, string $cutoff)
    {
        // Lot pages are independent of item pages and never eager-load piece/link collections.
        $query = $this->lotBalanceQuery($tenantId, [$itemId], $cutoff)
            ->when($filter->currencyCode !== null, fn ($q) => $q->where('lot.currency_code', $filter->currencyCode));
        $this->filterHolding($query, $filter->holding, 'COALESCE(balance.owned, 0)');
        return $query->orderBy('lot.acquired_at')->orderBy('lot.id')->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function movementPage(int $tenantId, int $itemId, OwnershipMovementFilter $filter)
    {
        // Bind optional historical lot metadata to the same tenant and aggregate.
        return DB::table('ownership_movements as movement')->leftJoin('acquisition_lots as lot', function ($join): void {
            $join->on('lot.id', '=', 'movement.acquisition_lot_id')->on('lot.tenant_id', '=', 'movement.tenant_id')
                ->on('lot.owned_item_id', '=', 'movement.owned_item_id');
        })->where('movement.tenant_id', $tenantId)->where('movement.owned_item_id', $itemId)
            ->select('movement.*', 'lot.code as lot_code')->orderByDesc('movement.occurred_at')->orderByDesc('movement.id')
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function receiptFacts(int $tenantId, array $lotIds): Collection
    {
        // Aggregate original linked and still-owned receipt quantities once per requested batch.
        return DB::table('ownership_receipt_links')->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
            ->select('acquisition_lot_id')->selectRaw('SUM(linked_quantity) AS linked, SUM(remaining_owned_quantity) AS received')
            ->groupBy('acquisition_lot_id')->get()->keyBy('acquisition_lot_id');
    }

    public function receiptAllocatedTotals(int $tenantId, array $receiptIds): Collection
    {
        // Allocation capacity counts original links, including quantities since sold or otherwise consumed.
        return DB::table('ownership_receipt_links')->where('tenant_id', $tenantId)->whereIn('purchase_receipt_line_id', $receiptIds)
            ->select('purchase_receipt_line_id')->selectRaw('SUM(linked_quantity) AS linked')
            ->groupBy('purchase_receipt_line_id')->get()->keyBy('purchase_receipt_line_id');
    }

    public function linkedPieceIds(int $tenantId, array $pieceCodes): Collection
    {
        // Candidate page state is bounded to its visible pieces; no entire item membership expansion.
        return DB::table('ownership_piece_memberships as membership')->join('inventory_serialized_items as piece', function ($join): void {
            $join->on('piece.id', '=', 'membership.inventory_serialized_item_id')->on('piece.tenant_id', '=', 'membership.tenant_id');
        })->where('membership.tenant_id', $tenantId)->whereNull('membership.ended_at')->whereIn('piece.code', $pieceCodes)
            ->pluck('piece.code')->flip();
    }

    public function lotFacts(int $tenantId, array $itemIds): Collection
    {
        // Receipt state and lot counts stay batched, without expanding a summary into its lots.
        $links = DB::table('ownership_receipt_links')->where('tenant_id', $tenantId)->select('acquisition_lot_id')
            ->selectRaw('SUM(linked_quantity) AS linked, SUM(remaining_owned_quantity) AS received')->groupBy('acquisition_lot_id');
        return DB::table('acquisition_lots as lot')->leftJoinSub($links, 'link', 'link.acquisition_lot_id', '=', 'lot.id')
            ->where('lot.tenant_id', $tenantId)->whereIn('lot.owned_item_id', $itemIds)->select('lot.owned_item_id')
            ->selectRaw('COUNT(*) AS lot_count, SUM(lot.acquired_quantity) AS acquired,
                SUM(COALESCE(link.linked, 0)) AS linked, SUM(COALESCE(link.received, 0)) AS received')
            ->groupBy('lot.owned_item_id')->get()->keyBy('owned_item_id');
    }

    private function lotBalanceQuery(int $tenantId, array $itemIds, string $cutoff): Builder
    {
        // Saved lot bases must join through the selected tenant/item checkpoint, never merely a lot ID.
        $base = DB::table('ownership_monthly_checkpoint_balances as child')
            ->joinSub($this->latestCompletedCheckpointQuery($tenantId, $itemIds, $cutoff), 'header', function ($join): void {
                $join->on('header.id', '=', 'child.ownership_monthly_checkpoint_id')->on('header.tenant_id', '=', 'child.tenant_id');
            })->join('acquisition_lots as base_lot', function ($join): void {
                $join->on('base_lot.id', '=', 'child.acquisition_lot_id')->on('base_lot.tenant_id', '=', 'header.tenant_id')
                    ->on('base_lot.owned_item_id', '=', 'header.owned_item_id');
            })->select('child.acquisition_lot_id')->selectRaw('child.owned_quantity AS owned, child.reserved_quantity AS reserved, child.pledged_quantity AS pledged');
        $tail = $this->uncoveredMovementQuery($tenantId, $itemIds, $cutoff)->select('movement.acquisition_lot_id')
            ->selectRaw('SUM(movement.owned_delta) AS owned, SUM(movement.reserved_delta) AS reserved, SUM(movement.pledged_delta) AS pledged')
            ->groupBy('movement.acquisition_lot_id');
        $totals = DB::query()->fromSub($base->unionAll($tail), 'parts')->select('acquisition_lot_id')
            ->selectRaw('SUM(owned) AS owned, SUM(reserved) AS reserved, SUM(pledged) AS pledged')->groupBy('acquisition_lot_id');
        return DB::table('acquisition_lots as lot')->join('owned_items as owner', function ($join): void {
            $join->on('owner.id', '=', 'lot.owned_item_id')->on('owner.tenant_id', '=', 'lot.tenant_id');
        })->leftJoinSub($totals, 'balance', 'balance.acquisition_lot_id', '=', 'lot.id')
            ->where('lot.tenant_id', $tenantId)->whereIn('lot.owned_item_id', $itemIds)
            ->select('lot.*')->selectRaw($this->balanceColumns('balance'));
    }

    private function itemTotalsQuery(int $tenantId, ?array $ids, string $cutoff): Builder
    {
        // Resolve all tenant IDs as a subquery when filtering the register; no unbounded PHP identity list.
        $ids ??= DB::table('owned_items')->where('tenant_id', $tenantId)->select('id');
        $tail = $this->uncoveredMovementQuery($tenantId, $ids, $cutoff)->select('movement.owned_item_id')
            ->selectRaw('SUM(movement.owned_delta) AS owned, SUM(movement.reserved_delta) AS reserved, SUM(movement.pledged_delta) AS pledged')
            ->groupBy('movement.owned_item_id');
        $parts = $this->openingItemBalanceQuery($tenantId, $ids, $cutoff)->unionAll($tail);
        return DB::query()->fromSub($parts, 'parts')->select('owned_item_id')
            ->selectRaw('SUM(owned) AS owned, SUM(reserved) AS reserved, SUM(pledged) AS pledged')->groupBy('owned_item_id');
    }

    private function balanceColumns(string $alias): string
    {
        // These aliases are internal constants; no client-selected SQL identifiers are accepted.
        return "CAST(COALESCE($alias.owned, 0) AS DECIMAL(24,3)) AS owned,
            CAST(COALESCE($alias.reserved, 0) AS DECIMAL(24,3)) AS reserved,
            CAST(COALESCE($alias.pledged, 0) AS DECIMAL(24,3)) AS pledged,
            CAST(COALESCE($alias.owned, 0)-COALESCE($alias.reserved, 0)-COALESCE($alias.pledged, 0) AS DECIMAL(24,3)) AS available";
    }

    private function filterHolding(Builder $query, string $holding, string $column): void
    {
        // Apply reviewed holding semantics in SQL so page totals match the selected filter.
        if ($holding !== 'all') $query->whereRaw($column.($holding === 'current' ? ' > 0' : ' = 0'));
    }
}
