<?php

namespace App\Repository\Ownership;

use App\Exceptions\TenantNotFound;
use App\Models\OwnershipModule\OwnershipMovement;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipReceiptLink, OwnershipPieceMembership, OwnershipCommitment, OwnershipPieceCommitment};
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Current locking reads and narrow persistence; transition rules stay in services. */
trait StateQueries
{
    public function sourceMovements(int $tenantId, int $ownedItemId, string $type, string $code): Collection
    {
        // Return validation reads actual current reversal rows under the already-held aggregate anchor.
        $this->requireLockTransaction();
        return OwnershipMovement::query()->where('tenant_id', $tenantId)
            ->where('owned_item_id', $ownedItemId)->where('source_type', $type)->where('source_code', $code)
            ->orderBy('id')->lockForUpdate()->get();
    }

    public function findOwnedByCode(int $tenantId, string $code, bool $lock = false): ?OwnedItem
    {
        // New bounded paths deliberately avoid the legacy eager-loaded aggregate lookup.
        return OwnedItem::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function lockedLots(int $tenantId, int $itemId): Collection
    {
        // Lock the complete lot set by ID; allocation order is a separate service responsibility.
        return $this->lockedLotsForItems($tenantId, [$itemId]);
    }

    public function lockedAggregates(int $tenantId, array $inventoryItemIds): Collection
    {
        // Acquire aggregate sets only after the complete Inventory identity set is locked.
        $this->requireLockTransaction();
        if ($inventoryItemIds === []) return collect();
        return OwnedItem::query()->where('tenant_id', $tenantId)->whereIn('inventory_item_id', $inventoryItemIds)
            ->orderBy('inventory_item_id')->orderBy('id')->lockForUpdate()->get();
    }

    public function lockAggregate(int $tenantId, int $ownedItemId): ?OwnedItem
    {
        // Monthly publication and all movement appends share this retained aggregate anchor.
        $this->requireLockTransaction();
        return OwnedItem::query()->where('tenant_id', $tenantId)->whereKey($ownedItemId)->lockForUpdate()->first();
    }

    public function lockedLotsForItems(int $tenantId, array $ownedItemIds): Collection
    {
        // Database ID order is independent of input order and later FIFO business allocation.
        $this->requireLockTransaction();
        if ($ownedItemIds === []) return collect();
        return AcquisitionLot::query()->where('tenant_id', $tenantId)->whereIn('owned_item_id', $ownedItemIds)
            ->orderBy('owned_item_id')->orderBy('id')->lockForUpdate()->get();
    }

    public function receiptLinks(int $tenantId, array $lotIds, bool $lock = false): Collection
    {
        // Keep original and remaining allocation quantities together for capacity validation.
        return OwnershipReceiptLink::query()->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function currentMemberships(int $tenantId, array $lotIds, bool $lock = false,
        ?int $ownedItemId = null, ?int $receiptLinkId = null, ?array $pieceIds = null): Collection
    {
        // Only open episodes authorize current use; historical episodes remain retained.
        return OwnershipPieceMembership::query()->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
            ->when($ownedItemId !== null, fn ($query) => $query->where('owned_item_id', $ownedItemId))
            ->when($receiptLinkId !== null, fn ($query) => $query->where('ownership_receipt_link_id', $receiptLinkId))
            ->when($pieceIds !== null, fn ($query) => $query->whereIn('inventory_serialized_item_id', $pieceIds))
            ->whereNull('ended_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function commitments(int $tenantId, ?array $lotIds, bool $lock = false, ?int $ownedItemId = null,
        ?string $kind = null, ?string $sourceType = null, ?string $sourceCode = null): Collection
    {
        // Filter before loading/locking; retain exhausted rows only for the requested source's history.
        if ($lotIds === null && $ownedItemId === null) throw new LogicException('Source commitments require an item or lot scope.');
        return OwnershipCommitment::query()->where('tenant_id', $tenantId)
            ->when($lotIds !== null, fn ($query) => $query->whereIn('acquisition_lot_id', $lotIds))
            ->when($ownedItemId !== null, fn ($query) => $query->where('owned_item_id', $ownedItemId))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($sourceType !== null, fn ($query) => $query->where('source_type', $sourceType))
            ->when($sourceCode !== null, fn ($query) => $query->where('source_code', $sourceCode))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function currentMembershipsForPieces(int $tenantId, array $pieceIds): Collection
    {
        // Exact piece IDs come from authoritative Inventory custody under the shared item/aggregate anchors.
        $this->requireLockTransaction();
        if ($pieceIds === []) return collect();
        return OwnershipPieceMembership::query()->where('tenant_id', $tenantId)
            ->whereIn('inventory_serialized_item_id', $pieceIds)->whereNull('ended_at')->orderBy('id')->lockForUpdate()->get();
    }

    public function latestLotMembershipsForPieces(int $tenantId, int $ownedItemId, int $lotId, array $pieceIds): Collection
    {
        // Resolve one latest historical episode per exact piece without loading all retained episodes.
        $latest = OwnershipPieceMembership::query()->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->where('acquisition_lot_id', $lotId)->whereIn('inventory_serialized_item_id', $pieceIds)
            ->selectRaw('MAX(id) AS id')->groupBy('inventory_serialized_item_id');
        return OwnershipPieceMembership::query()->where('tenant_id', $tenantId)->whereIn('id', $latest)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('inventory_serialized_item_id');
    }

    public function insertOwnershipMemberships(array $rows): void
    {
        // Fixed service-built rows retain the database's single-current-piece uniqueness guard.
        $this->requireLockTransaction();
        for ($offset = 0; $offset < count($rows); $offset += 500) {
            OwnershipPieceMembership::query()->insert(array_slice($rows, $offset, 500));
        }
    }

    public function createSourcePieceHolds(int $tenantId, int $commitmentId, array $membershipIds, CarbonImmutable $now): void
    {
        // Bound INSERT bindings while retaining one source-bound open hold per selected membership.
        $this->requireLockTransaction();
        for ($offset = 0; $offset < count($membershipIds); $offset += 500) {
            $rows = array_map(fn ($id) => ['tenant_id' => $tenantId, 'ownership_commitment_id' => $commitmentId,
                'ownership_piece_membership_id' => $id, 'created_at' => $now, 'updated_at' => $now], array_slice($membershipIds, $offset, 500));
            OwnershipPieceCommitment::query()->insert($rows);
        }
    }

    public function endSourcePieceHolds(int $tenantId, int $commitmentId, array $holdIds, CarbonImmutable $now): int
    {
        // An incomplete/foreign set is detected by the caller and rolls back its outer operation.
        $this->requireLockTransaction();
        $updated = 0;
        for ($offset = 0; $offset < count($holdIds); $offset += 500) {
            $updated += OwnershipPieceCommitment::query()->where('tenant_id', $tenantId)->where('ownership_commitment_id', $commitmentId)
                ->whereIn('id', array_slice($holdIds, $offset, 500))->whereNull('ended_at')->update(['ended_at' => $now]);
        }
        return $updated;
    }

    public function endLotPieceMemberships(int $tenantId, int $lotId, array $membershipIds, CarbonImmutable $now): int
    {
        // End exact current episodes in batches; original receipt/source history remains retained.
        $this->requireLockTransaction();
        $updated = 0;
        for ($offset = 0; $offset < count($membershipIds); $offset += 500) {
            $updated += OwnershipPieceMembership::query()->where('tenant_id', $tenantId)->where('acquisition_lot_id', $lotId)
                ->whereIn('id', array_slice($membershipIds, $offset, 500))->whereNull('ended_at')->update(['ended_at' => $now]);
        }
        return $updated;
    }

    public function pieceCommitments(int $tenantId, array $membershipIds, bool $lock = false): Collection
    {
        // Active piece holds are independent of the item's aggregate quantity.
        return OwnershipPieceCommitment::query()->where('tenant_id', $tenantId)->whereIn('ownership_piece_membership_id', $membershipIds)
            ->whereNull('ended_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function lockedOwnedQuantity(int $tenantId, int $ownedItemId): string
    {
        // UNIQUE acquisition needs one total, not every old lot or movement model in PHP.
        $this->requireLockTransaction();
        $header = DB::table('ownership_monthly_checkpoints')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->whereNotNull('completed_at')->where('cutoff_at', '<=', CarbonImmutable::now('UTC')->toDateTimeString())
            ->orderByDesc('month')->lockForUpdate()->first();
        $tail = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $ownedItemId)
            ->when($header !== null, fn ($query) => $query->where(function ($coverage) use ($header): void {
                $coverage->where('id', '>', $header->movement_watermark)->orWhere('created_at', '>=', $header->cutoff_at)->orWhereNull('created_at');
            }))->selectRaw('CAST(? + COALESCE(SUM(owned_delta), 0) AS DECIMAL(24,3)) AS owned', [$header->owned_quantity ?? '0.000'])
            ->lockForUpdate()->first();
        return (string) $tail->owned;
    }

    public function lockedBalanceParts(int $tenantId, int $itemId, string $cutoff, ?array $lotIds = null): array
    {
        // Use current locking rows after the aggregate anchor, even with an older repeatable-read snapshot.
        if ($this->lockAggregate($tenantId, $itemId) === null) {
            throw new TenantNotFound(__('ownership.not_found'));
        }
        $header = DB::table('ownership_monthly_checkpoints')->where('tenant_id', $tenantId)->where('owned_item_id', $itemId)
            ->whereNotNull('completed_at')->where('cutoff_at', '<=', $cutoff)->orderByDesc('month')->lockForUpdate()->first();
        // A saved child must belong to a real lot of this item as well as the selected parent.
        $base = $header === null ? collect() : DB::table('ownership_monthly_checkpoint_balances as balance')
            ->join('acquisition_lots as lot', 'lot.id', '=', 'balance.acquisition_lot_id')
            ->where('balance.tenant_id', $tenantId)->where('lot.tenant_id', $tenantId)->where('lot.owned_item_id', $itemId)
            ->where('balance.ownership_monthly_checkpoint_id', $header->id)
            ->when($lotIds !== null, fn ($query) => $query->whereIn('balance.acquisition_lot_id', $lotIds))
            ->orderBy('balance.id')->select('balance.*')->lockForUpdate()->get()->keyBy('acquisition_lot_id');
        $tail = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $itemId)
            ->when($lotIds !== null, fn ($query) => $query->whereIn('acquisition_lot_id', $lotIds))
            ->when($header !== null, fn ($q) => $q->where(function ($q) use ($header): void {
                $q->where('id', '>', $header->movement_watermark)->orWhere('created_at', '>=', $header->cutoff_at)->orWhereNull('created_at');
            }))->orderBy('id')->lockForUpdate()->get();
        return [$base, $tail];
    }

    public function createReceiptLink(array $data): OwnershipReceiptLink
    {
        // Persist only the service-validated link fields.
        return OwnershipReceiptLink::query()->create($data);
    }

    public function createMembership(array $data): OwnershipPieceMembership
    {
        // Database uniqueness independently protects one open episode per physical piece.
        return OwnershipPieceMembership::query()->create($data);
    }

    public function createCommitment(array $data): OwnershipCommitment
    {
        // Quantity commitments remain source/lot bound and do not replace the ledger.
        return OwnershipCommitment::query()->create($data);
    }

    public function createPieceCommitment(array $data): OwnershipPieceCommitment
    {
        // Open membership holds retain history when later ended.
        return OwnershipPieceCommitment::query()->create($data);
    }

    public function updateCommitment(int $tenantId, OwnershipCommitment $commitment, string $quantity, string $remaining): bool
    {
        // Only commitment quantities may change; source/item/lot identity remains historical.
        return $this->persistState($tenantId, $commitment, ['quantity' => $quantity, 'remaining_quantity' => $remaining]);
    }

    public function updateReceiptRemaining(int $tenantId, OwnershipReceiptLink $link, string $remaining): bool
    {
        // Original linked capacity and receipt/source identity are never edited by consumption.
        return $this->persistState($tenantId, $link, ['remaining_owned_quantity' => $remaining]);
    }

    public function endMembership(int $tenantId, OwnershipPieceMembership $membership, CarbonImmutable $endedAt): bool
    {
        // End an open episode once; retained history cannot be reopened or timestamp-rewritten here.
        return $this->persistState($tenantId, $membership, ['ended_at' => $endedAt], true);
    }

    public function endPieceCommitment(int $tenantId, OwnershipPieceCommitment $commitment, CarbonImmutable $endedAt): bool
    {
        // Piece holds retain their original membership/source relationship when released or consumed.
        return $this->persistState($tenantId, $commitment, ['ended_at' => $endedAt], true);
    }

    public function advanceVersion(OwnedItem $item): int
    {
        // The caller advances once for a logical mutation; read-only allocation never invokes this.
        $item->increment('update_key');
        return (int) $item->update_key;
    }

    private function persistState(int $tenantId, Model $model, array $data, bool $openOnly = false): bool
    {
        // Narrow public helpers supply fixed columns; every write includes tenant and primary-key scope.
        $updated = $model->newQuery()->where('tenant_id', $tenantId)->whereKey($model->getKey())
            ->when($openOnly, fn ($query) => $query->whereNull('ended_at'))->update($data);
        if ($updated !== 1) return false;
        $model->fill($data)->syncOriginal();
        return true;
    }

    private function requireLockTransaction(): void
    {
        // Autocommit would release the anchor before its protected read or append.
        if (DB::transactionLevel() === 0) throw new LogicException('Ownership locking requires an active transaction.');
    }
}
