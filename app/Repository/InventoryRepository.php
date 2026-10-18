<?php

namespace App\Repository;

use App\Models\InventoryModule\InventoryItem;
use App\Models\InventoryModule\InventoryLocation;
use App\Models\InventoryModule\InventoryMovement;
use App\Models\InventoryModule\InventorySerializedItem;
use App\Models\InventoryModule\InventoryReservation;
use App\Models\InventoryModule\InventoryMonthlyCheckpoint;
use Carbon\CarbonImmutable;
use App\Exceptions\TenantNotFound;
use App\Support\TenantContext;
use App\DataObjects\RequestObjects\InventoryListFilter;
use App\DataObjects\RequestObjects\InventorySerializedItemFilter;
use App\DataObjects\RequestObjects\InventoryPageFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\LazyCollection;
use LogicException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Provides Inventory persistence, tenant-scoped lookups, and batched read queries.
 * New queries use TenantContext and distinguish browse snapshots from current
 * locking reads; calling services own business validation and transactions.
 */
class InventoryRepository
{
    public function locations(int $tenantId): Collection
    {
        return InventoryLocation::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Resolve a location by public business code within the current TenantContext.
     * The model's BelongToTenant global scope applies the tenant restriction.
     * Exclusive locking supports location edits; shared locking supports stock
     * operations that must prevent concurrent location restriction changes.
     * Exclusive locking takes precedence when both flags are enabled.
     * Locks require a caller-owned transaction that stays open until work finishes.
     * Return the complete model, including update_key, or null when not found.
     */
    public function findLocationByCode(
        string $code,
        bool $lock = false,
        bool $sharedLock = false,
    ): ?InventoryLocation
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Let BelongToTenant scope this code lookup using the current TenantContext.
        $query = InventoryLocation::query()
            ->where('code', $code);

        // Preserve exclusive locking for existing callers and add an optional shared lock.
        if ($lock) {
            $query->lockForUpdate();
        } elseif ($sharedLock) {
            $query->sharedLock();
        }

        // Keep missing-record handling and location eligibility decisions in the service.
        return $query->first();
    }

    public function createLocation(int $tenantId, array $data): InventoryLocation
    {
        return InventoryLocation::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function locationNameExists(string $name, ?int $exceptId = null): bool
    {
        // Use TenantContext and database collation for the pre-write name guard.
        return $this->inventoryQuery(InventoryLocation::class)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function updateLocation(InventoryLocation $location, array $data): InventoryLocation
    {
        $location->update($data);
        return $location->refresh();
    }

    public function ensureMainShop(int $tenantId, string $code): InventoryLocation
    {
        return InventoryLocation::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Main Shop'],
            ['code' => $code, 'type' => 'SHOP', 'is_default' => true, 'is_active' => true],
        );
    }

    public function defaultLocation(int $tenantId): ?InventoryLocation
    {
        return InventoryLocation::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function items(int $tenantId, ?string $search): Collection
    {
        return InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->when($search, fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->with(['catalogItem:id,business_code,name', 'unit:id,code,name'])
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * Resolve an item by public business code within the current TenantContext.
     * The model's BelongToTenant global scope applies the tenant restriction.
     * The full model includes update_key for later service-level version checks.
     * With locking enabled, the caller must own an active transaction and keep it
     * open until its stock operation finishes; this method does not start one.
     * Missing items return null so the service can choose the domain response.
     */
    public function findItemByCode(string $code, bool $lock = false): ?InventoryItem
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Let BelongToTenant scope this code lookup using the current TenantContext.
        $query = InventoryItem::query()
            ->where('code', $code);

        // Acquire the item anchor's exclusive lock within the caller's transaction.
        if ($lock) {
            $query->lockForUpdate();
        }

        // Return the complete item, including its version, without loading related collections.
        return $query->first();
    }

    public function createItem(int $tenantId, array $data): InventoryItem
    {
        return InventoryItem::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function createSerializedItem(int $tenantId, int $itemId, ?string $identifier, string $code): InventorySerializedItem
    {
        return InventorySerializedItem::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'inventory_item_id' => $itemId,
            'identifier' => $identifier,
        ]);
    }

    public function serializedItemIdentifierExists(string $identifier): bool
    {
        // Match tenant-wide identifier uniqueness, preserving identifiers such as "0".
        return $this->inventoryQuery(InventorySerializedItem::class)
            ->where('identifier', $identifier)
            ->exists();
    }

    /**
     * Lock selected unit identities within TenantContext and their expected item.
     * Missing/wrong-item codes are omitted for the service to reject as a complete set.
     * Callers acquire item/location/reservation anchors first and own the transaction.
     */
    public function findSerializedItemsByCodesForUpdate(int $itemId, array $unitCodes): Collection
    {
        // Preserve deterministic lock ordering and prevent locks outside a transaction.
        $this->assertInventoryTransaction();

        return $this->inventoryQuery(InventorySerializedItem::class)
            ->where('inventory_item_id', $itemId)
            ->whereIn('code', $unitCodes)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function createMovement(array $data): ?InventoryMovement
    {
        // Serialize every append against checkpoint publication using the item anchor.
        $this->assertInventoryTransaction();
        $item = $this->inventoryQuery(InventoryItem::class)
            ->whereKey($data['inventory_item_id'])->lockForUpdate()->first();
        if ($item === null) {
            // Let the service return its named exception before any movement is written.
            return null;
        }

        // Recording time is server UTC, never a backdated business timestamp.
        $recordedAt = CarbonImmutable::now('UTC');
        $data['tenant_id'] = app(TenantContext::class)->id();
        $movement = new InventoryMovement($data);
        $movement->created_at = $recordedAt;
        $movement->updated_at = $recordedAt;
        $movement->save();

        return $movement;
    }

    public function movements(int $tenantId, int $itemId): Collection
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->with(['fromLocation:id,code,name', 'toLocation:id,code,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    public function balance(int $tenantId, int $itemId, int $locationId): string
    {
        // Use the completed monthly base plus uncovered deltas for this location.
        $row = $this->movementBalancesForItems([$itemId], [$locationId])->first();

        return $row === null ? '0.000' : (string) $row->quantity;
    }

    public function balances(int $tenantId, int $itemId): array
    {
        // Preserve exact decimal strings while mapping checkpoint-backed location totals.
        return $this->movementBalancesForItems([$itemId])
            ->mapWithKeys(fn (object $row): array => [(int) $row->location_id => (string) $row->quantity])
            ->all();
    }

    public function reservedBalance(int $tenantId, int $itemId, int $locationId): float
    {
        return (float) InventoryReservation::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)->where('status', 'ACTIVE')->sum('remaining_quantity');
    }

    public function activeReservedSerializedItemCodes(int $tenantId, int $itemId, int $locationId): array
    {
        return InventoryReservation::query()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)->where('status', 'ACTIVE')->get(['serialized_item_codes'])
            ->flatMap(fn (InventoryReservation $row) => $row->serialized_item_codes ?? [])->all();
    }

    public function createReservation(int $tenantId, array $data): InventoryReservation
    {
        return InventoryReservation::query()->create(['tenant_id' => $tenantId] + $data);
    }

    /**
     * Resolve a reservation by public code within the current TenantContext.
     * An expected item ID optionally binds the lookup to a caller's resolved item.
     * Code-only lookup supports release workflows that first resolve the reservation.
     * Exclusive locking requires a caller-owned transaction; missing or mismatched
     * records return null for the service to handle without applying status filters.
     */
    public function reservationByCode(
        string $code,
        bool $lock = false,
        ?int $inventoryItemId = null,
    ): ?InventoryReservation
    {
        // Require context before relying on the model's automatic tenant scope.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        // Resolve the tenant-scoped code and optionally constrain its item binding.
        $query = InventoryReservation::query()
            ->where('code', $code)
            ->when($inventoryItemId !== null, fn ($query) => $query->where('inventory_item_id', $inventoryItemId));

        // Keep the reservation lock in the caller's transaction until its work completes.
        if ($lock) {
            $query->lockForUpdate();
        }

        // Leave status, location, source, and remaining-quantity validation to the service.
        return $query->first();
    }

    public function updateReservation(InventoryReservation $reservation, array $data): InventoryReservation
    {
        $reservation->update($data);
        return $reservation->refresh();
    }

    public function serializedItems(int $tenantId, int $itemId): Collection
    {
        return InventorySerializedItem::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->orderBy('id')
            ->get();
    }

    public function latestSerializedItemMovement(int $tenantId, int $unitId): ?InventoryMovement
    {
        return InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_serialized_item_id', $unitId)
            ->with(['fromLocation:id,code,name', 'toLocation:id,code,name'])
            ->latest('id')
            ->first();
    }

    /**
     * Group browse balances for an item page, returning exact signed decimal strings.
     * These snapshot aggregates are not authoritative mutation reads.
     */
    public function movementBalancesForItems(
        array $itemIds,
        ?array $locationIds = null,
        ?CarbonImmutable $recordedBefore = null,
    ): Collection {
        // Reuse the same checkpoint calculation for summaries, lists, and individual balances.
        return $this->movementBalanceQuery($itemIds, $locationIds, $recordedBefore)->get();
    }

    /**
     * Read current movement rows under locks after the caller locks item anchors.
     * The service converts individual decimal quantities to exact integer thousandths.
     * Missing rows represent zero stock; the item anchor serializes concurrent appends.
     */
    public function movementRowsForUpdate(array $itemIds, ?array $locationIds = null): Collection
    {
        // Item anchors exclude concurrent checkpoint publication and movement appends.
        $this->assertInventoryTransaction();

        // Return uncovered rows; callers add checkpointBalancesForItems as their opening base.
        return $this->uncoveredMovementQuery($itemIds)
            ->when($locationIds !== null, function ($query) use ($locationIds): void {
                $query->where(function ($query) use ($locationIds): void {
                    $query->whereIn('from_location_id', $locationIds)
                        ->orWhereIn('to_location_id', $locationIds);
                });
            })
            ->select('inventory_movements.*')
            ->orderBy('inventory_movements.id')
            ->lockForUpdate()
            ->get();
    }

    /** Group active remaining reservations for a browse page without float conversion. */
    public function reservationBalancesForItems(array $itemIds, ?array $locationIds = null): Collection
    {
        // Reuse the existing item/location/status index and keep inactive history excluded.
        return $this->inventoryQuery(InventoryReservation::class)
            ->whereIn('inventory_item_id', $itemIds)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('inventory_location_id', $locationIds))
            ->where('status', 'ACTIVE')
            ->select([
                'inventory_item_id',
                'inventory_location_id'
                ])
            ->selectRaw('CAST(SUM(remaining_quantity) AS CHAR) AS remaining_quantity')
            ->groupBy('inventory_item_id', 'inventory_location_id')
            ->orderBy('inventory_item_id')
            ->orderBy('inventory_location_id')
            ->get();
    }

    /**
     * Fetch active reservation membership in one batch for quantity and unit decisions.
     * Locked reads require item/location anchors acquired by the calling operation first.
     */
    public function activeReservationsForItems(
        array $itemIds,
        ?array $locationIds = null,
        bool $lock = false,
    ): Collection {
        // Validate the transaction before requesting authoritative current reservation rows.
        if ($lock) {
            $this->assertInventoryTransaction();
        }

        // Return each reservation once with decoded unit codes; avoid per-unit lookups.
        $query = $this->inventoryQuery(InventoryReservation::class)
            ->whereIn('inventory_item_id', $itemIds)
            ->when($locationIds !== null, fn ($query) => $query->whereIn('inventory_location_id', $locationIds))
            ->where('status', 'ACTIVE')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** Fetch tenant-scoped identifiers matching a batch, preserving the literal "0". */
    public function existingSerializedItemIdentifiers(array $identifiers): Collection
    {
        // Let database collation and tenant/identifier uniqueness determine matches.
        return $this->inventoryQuery(InventorySerializedItem::class)
            ->whereIn('identifier', $identifiers)
            ->orderBy('id')
            ->get([
                'id',
                'identifier'
                ]);
    }

    /**
     * Retrieve latest custody movements for selected units in one query plus batched locations.
     * This is a browse snapshot; write flows must use locked current movement rows instead.
     */
    public function latestMovementsForSerializedItems(array $unitIds): Collection
    {
        // Restrict latest-ID grouping to the requested unit batch within TenantContext.
        $latest = $this->latestSerializedItemMovementIds()->whereIn('inventory_serialized_item_id', $unitIds);

        // Join selected movement IDs and eager-load both locations without per-unit queries.
        return $this->inventoryQuery(InventoryMovement::class)
            ->joinSub($latest, 'latest_serialized_item_movements', function (JoinClause $join): void {
                $join->on('inventory_movements.id', '=', 'latest_serialized_item_movements.latest_id');
            })
            ->select('inventory_movements.*')
            ->with([
                'fromLocation',
                'toLocation'
                ])
            ->orderBy('inventory_movements.inventory_serialized_item_id')
            ->get();
    }

    /** Page item summaries with batched Catalog/unit references and no serialized-item collection. */
    public function itemPage(InventoryListFilter $filter): LengthAwarePaginator
    {
        // Preserve search "0" and stable ordering while avoiding the old 100-item hard cap.
        $query = $this->inventoryQuery(InventoryItem::class)
            ->when($filter->search !== null && $filter->search !== '', fn ($query) => $query->where('name', 'like', '%'.$filter->search.'%'))
            ->when($filter->trackingMode !== null, fn ($query) => $query->where('tracking_mode', $filter->trackingMode))
            ->with([
                'catalogItem',
                'unit'
                ]);

        // Filter location membership by positive net custody, rather than historical presence.
        if ($filter->locationCode !== null) {
            $location = $this->findLocationByCode($filter->locationCode);
            if ($location === null) {
                $query->whereRaw('1 = 0');
            } else {
                // Filter with the same compact opening base and live tail used by balance reads.
                $stock = $this->movementBalanceQuery(null, [(int) $location->id])
                    ->select('inventory_item_id')->havingRaw('SUM(quantity_delta) > 0');
                $query->whereIn('inventory_items.id', $stock);
            }
        }

        // Bind page size and page explicitly so reads do not depend on global request state.
        return $query->orderBy('name')->orderBy('id')
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    /** Page immutable movement history in deterministic occurrence-time/ID order. */
    public function movementPage(int $itemId, InventoryPageFilter $filter): LengthAwarePaginator
    {
        // Restrict by current tenant and item; batch related locations, unit, and actor.
        return $this->inventoryQuery(InventoryMovement::class)
            ->where('inventory_item_id', $itemId)
            ->with([
                'fromLocation',
                'toLocation',
                'serializedItem',
                'createdBy'
                ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    /**
     * Page serialized items with current custody and active reservation membership.
     * JSON membership uses MySQL JSON_CONTAINS to match the production database.
     */
    public function serializedItemPage(int $itemId, InventorySerializedItemFilter $filter): LengthAwarePaginator
    {
        // Build a tenant-scoped latest-custody subquery limited to this item.
        $latest = $this->latestSerializedItemMovementIds()->where('inventory_item_id', $itemId);
        $reservations = $this->inventoryQuery(InventoryReservation::class)
            ->where('inventory_item_id', $itemId)
            ->where('status', 'ACTIVE')
            ->whereRaw('JSON_CONTAINS(inventory_reservations.serialized_item_codes, JSON_QUOTE(inventory_serialized_items.code))');

        // Join one latest movement and its current location per unit, preventing duplicate rows.
        $query = $this->inventoryQuery(InventorySerializedItem::class)
            ->where('inventory_serialized_items.inventory_item_id', $itemId)
            ->leftJoinSub($latest, 'latest_serialized_item_movements', function (JoinClause $join): void {
                $join->on('inventory_serialized_items.id', '=', 'latest_serialized_item_movements.inventory_serialized_item_id');
            })
            ->leftJoin('inventory_movements as custody', function (JoinClause $join): void {
                $join->on('custody.id', '=', 'latest_serialized_item_movements.latest_id')
                    ->on('custody.tenant_id', '=', 'inventory_serialized_items.tenant_id');
            })
            ->leftJoin('inventory_locations as custody_location', function (JoinClause $join): void {
                $join->on('custody_location.id', '=', 'custody.to_location_id')
                    ->on('custody_location.tenant_id', '=', 'inventory_serialized_items.tenant_id');
            })
            ->select([
                'inventory_serialized_items.*',
                'custody.code as latest_movement_code',
                'custody.to_location_id as current_location_id',
                'custody_location.code as current_location_code',
                'custody_location.name as current_location_name',
                'custody_location.type as current_location_type',
                'custody_location.is_active as current_location_is_active',
                'custody_location.is_sellable as current_location_is_sellable',
                'custody_location.is_default as current_location_is_default',
                'custody_location.update_key as current_location_update_key'
                ])
            ->selectSub((clone $reservations)->selectRaw('1')->limit(1)->toBase(), 'is_reserved')
            ->when($filter->search !== null && $filter->search !== '', function ($query) use ($filter): void {
                $query->where(function ($query) use ($filter): void {
                    $query->where('inventory_serialized_items.identifier', 'like', '%'.$filter->search.'%')
                        ->orWhere('inventory_serialized_items.code', 'like', '%'.$filter->search.'%');
                });
            })
            ->when($filter->locationCode !== null, fn ($query) => $query->where('custody_location.code', $filter->locationCode));

        // Physical availability means in custody and unreserved; sellability remains a separate flag.
        if ($filter->availableOnly) {
            $query->whereNotNull('custody_location.id')
                ->whereNotExists((clone $reservations)->selectRaw('1')->toBase());
        }

        return $query->orderBy('inventory_serialized_items.id')
            ->paginate($filter->perPage, ['inventory_serialized_items.*'], 'page', $filter->page);
    }

    public function movementBySource(int $tenantId, string $sourceType, string $sourceCode): ?InventoryMovement
    {
        return InventoryMovement::query()->where('tenant_id', $tenantId)->where('source_type', $sourceType)
            ->where('source_code', $sourceCode)->latest('id')->first();
    }

    /** Stream item IDs within the current tenant without loading the complete register. */
    public function inventoryItemIdsForSummary(): LazyCollection
    {
        // Bound this run to the current register so continuous new-item creation cannot prolong it.
        $lastItemId = (int) $this->inventoryQuery(InventoryItem::class)->max('id');

        // ID chunking keeps memory bounded; newly created items remain correct through live reads.
        return $this->inventoryQuery(InventoryItem::class)
            ->where('id', '<=', $lastItemId)->select('id')->lazyById(100);
    }

    /** Lock the item anchor before summarizing or appending movements for that item. */
    public function lockItemForSummary(int $itemId): ?InventoryItem
    {
        // The owning service starts the transaction; the tenant scope rejects foreign items.
        $this->assertInventoryTransaction();

        return $this->inventoryQuery(InventoryItem::class)->whereKey($itemId)->lockForUpdate()->first();
    }

    /** Find an already published month so retrying a completed item does no additional work. */
    public function monthlyCheckpoint(int $itemId, string $month): ?InventoryMonthlyCheckpoint
    {
        // Check only within the current tenant and resolved item.
        return $this->inventoryQuery(InventoryMonthlyCheckpoint::class)
            ->where('inventory_item_id', $itemId)->where('month', $month)->first();
    }

    /** Record the highest committed movement ID while the caller owns the item anchor. */
    public function movementWatermark(int $itemId): int
    {
        // Writers cannot append to this item until checkpoint publication completes.
        $this->assertInventoryTransaction();

        return (int) $this->inventoryQuery(InventoryMovement::class)
            ->where('inventory_item_id', $itemId)->max('id');
    }

    /** Publish checkpoint metadata and exact location balances in the caller's transaction. */
    public function createMonthlyCheckpoint(
        int $itemId,
        string $month,
        string $timezone,
        CarbonImmutable $cutoff,
        int $watermark,
        Collection $balances,
    ): InventoryMonthlyCheckpoint {
        // Metadata and all balance rows become visible together after commit.
        $this->assertInventoryTransaction();
        $checkpoint = $this->inventoryQuery(InventoryMonthlyCheckpoint::class)->create([
            'inventory_item_id' => $itemId,
            'month' => $month,
            'timezone' => $timezone,
            'cutoff_at' => $cutoff,
            'movement_watermark' => $watermark,
            'completed_at' => CarbonImmutable::now('UTC')
            ]);

        // Bound insert size independently of the number of historical storage locations.
        foreach ($balances->chunk(500) as $chunk) {
            $rows = $chunk->map(fn (object $row): array => [
                'inventory_monthly_checkpoint_id' => $checkpoint->id,
                'inventory_location_id' => $row->location_id,
                'quantity' => (string) $row->quantity
                ])->all();
            DB::table('inventory_monthly_checkpoint_balances')->insert($rows);
        }

        return $checkpoint;
    }

    /**
     * Read the immutable opening base for current write-side calculations.
     * Callers must lock item anchors first, then add movementRowsForUpdate deltas.
     */
    public function checkpointBalancesForItems(array $itemIds, ?array $locationIds = null): Collection
    {
        // The checkpoint's parent is tenant-scoped; child rows cannot escape that scope.
        return $this->checkpointBalanceQuery($itemIds, $locationIds)
            ->select([
                'stock_checkpoint.inventory_item_id',
                'checkpoint_balances.inventory_location_id as location_id',
                'checkpoint_balances.quantity'
                ])->get();
    }

    /** Build exact base-plus-delta totals; null item IDs mean a tenant-scoped location filter. */
    private function movementBalanceQuery(
        ?array $itemIds,
        ?array $locationIds = null,
        ?CarbonImmutable $recordedBefore = null,
    ): QueryBuilder {
        // Add only rows not represented in the latest completed checkpoint.
        $incoming = $this->uncoveredMovementQuery($itemIds, $recordedBefore)
            ->whereNotNull('to_location_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('to_location_id', $locationIds))
            ->selectRaw('inventory_movements.inventory_item_id, to_location_id AS location_id, quantity AS quantity_delta');
        $outgoing = $this->uncoveredMovementQuery($itemIds, $recordedBefore)
            ->whereNotNull('from_location_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('from_location_id', $locationIds))
            ->selectRaw('inventory_movements.inventory_item_id, from_location_id AS location_id, -quantity AS quantity_delta');
        $base = $this->checkpointBalanceQuery($itemIds, $locationIds, $recordedBefore);

        // Sum base + incoming - outgoing with SQL DECIMAL, including exact exhaustion.
        return DB::query()->fromSub($base->unionAll($incoming->toBase())->unionAll($outgoing->toBase()), 'stock_deltas')
            ->select([
                'inventory_item_id',
                'location_id'
                ])
            ->selectRaw('CAST(SUM(quantity_delta) AS CHAR) AS quantity')
            ->groupBy('inventory_item_id', 'location_id')
            ->orderBy('inventory_item_id')
            ->orderBy('location_id');
    }

    /** Select the latest published checkpoint for each requested item and cutoff. */
    private function latestInventoryCheckpoints(?array $itemIds, ?CarbonImmutable $cutoff = null): Builder
    {
        // A closed checkpoint can never be selected before its stored UTC boundary.
        $boundary = ($cutoff ?? CarbonImmutable::now('UTC'))->format('Y-m-d H:i:s');
        $months = $this->inventoryQuery(InventoryMonthlyCheckpoint::class)
            ->when($itemIds !== null, fn ($query) => $query->whereIn('inventory_item_id', $itemIds))
            ->where('cutoff_at', '<=', $boundary)
            ->select('inventory_item_id')->selectRaw('MAX(month) AS latest_month')
            ->groupBy('inventory_item_id');

        return $this->inventoryQuery(InventoryMonthlyCheckpoint::class)
            ->joinSub($months, 'latest_checkpoint_months', function (JoinClause $join): void {
                $join->on('inventory_monthly_checkpoints.inventory_item_id', '=', 'latest_checkpoint_months.inventory_item_id')
                    ->on('inventory_monthly_checkpoints.month', '=', 'latest_checkpoint_months.latest_month');
            })
            ->select('inventory_monthly_checkpoints.*');
    }

    /** Return opening location quantities as one branch of the exact balance UNION. */
    private function checkpointBalanceQuery(
        ?array $itemIds,
        ?array $locationIds = null,
        ?CarbonImmutable $cutoff = null,
    ): QueryBuilder {
        // Only balances reached through a tenant-scoped completed header can participate.
        return DB::query()->fromSub($this->latestInventoryCheckpoints($itemIds, $cutoff)->toBase(), 'stock_checkpoint')
            ->join('inventory_monthly_checkpoint_balances as checkpoint_balances', 'checkpoint_balances.inventory_monthly_checkpoint_id', '=', 'stock_checkpoint.id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('checkpoint_balances.inventory_location_id', $locationIds))
            ->selectRaw('stock_checkpoint.inventory_item_id, checkpoint_balances.inventory_location_id AS location_id, checkpoint_balances.quantity AS quantity_delta');
    }

    /**
     * Select only movements omitted from the monthly opening base.
     * The ID watermark retains late writes; the recording cutoff retains current-month
     * movements allocated IDs before the summarizer ran. Null recording times stay live.
     */
    private function uncoveredMovementQuery(?array $itemIds, ?CarbonImmutable $recordedBefore = null): Builder
    {
        // Use the same completed checkpoint definition for the base and all delta branches.
        $query = $this->inventoryQuery(InventoryMovement::class)
            ->when($itemIds !== null, fn ($query) => $query->whereIn('inventory_movements.inventory_item_id', $itemIds))
            ->leftJoinSub($this->latestInventoryCheckpoints($itemIds, $recordedBefore), 'stock_checkpoint', function (JoinClause $join): void {
                $join->on('inventory_movements.inventory_item_id', '=', 'stock_checkpoint.inventory_item_id');
            })
            ->where(function ($query): void {
                $query->whereNull('stock_checkpoint.id')
                    ->orWhereColumn('inventory_movements.id', '>', 'stock_checkpoint.movement_watermark')
                    ->orWhereColumn('inventory_movements.created_at', '>=', 'stock_checkpoint.cutoff_at')
                    ->orWhereNull('inventory_movements.created_at');
            });

        // Closing summaries exclude the new month; ordinary live reads have no upper cutoff.
        if ($recordedBefore !== null) {
            $query->where('inventory_movements.created_at', '<', $recordedBefore->format('Y-m-d H:i:s'));
        }

        return $query;
    }

    /** Build latest-ID grouping shared by batched custody and serialized-item page reads. */
    private function latestSerializedItemMovementIds(): Builder
    {
        // Movement IDs define authoritative append order for each serialized identity.
        return $this->inventoryQuery(InventoryMovement::class)
            ->whereNotNull('inventory_serialized_item_id')
            ->select('inventory_serialized_item_id')
            ->selectRaw('MAX(id) AS latest_id')
            ->groupBy('inventory_serialized_item_id');
    }

    /** Resolve tenant scope once for new Inventory queries, failing before an unscoped read. */
    private function inventoryQuery(string $modelClass): Builder
    {
        // Reuse the models' TenantContext global scope rather than accepting a tenant ID.
        if (! app(TenantContext::class)->hasTenant()) {
            throw new TenantNotFound(null);
        }

        return $modelClass::query();
    }

    /** Assert that current locking reads remain protected by their calling transaction. */
    private function assertInventoryTransaction(): void
    {
        // This repository never owns transaction boundaries or retries.
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inventory locking reads require an active transaction.');
        }
    }
}
