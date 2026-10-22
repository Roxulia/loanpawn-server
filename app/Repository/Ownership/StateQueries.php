<?php

namespace App\Repository\Ownership;

use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipReceiptLink, OwnershipPieceMembership, OwnershipCommitment, OwnershipPieceCommitment};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

/** Current locking reads and narrow persistence; transition rules stay in services. */
trait StateQueries
{
    public function findOwnedByCode(int $tenantId, string $code, bool $lock = false): ?OwnedItem
    {
        // New bounded paths deliberately avoid the legacy eager-loaded aggregate lookup.
        return OwnedItem::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function lockedLots(int $tenantId, int $itemId): Collection
    {
        // Lock the complete lot set by ID; allocation order is a separate service responsibility.
        return AcquisitionLot::query()->where('tenant_id', $tenantId)->where('owned_item_id', $itemId)
            ->orderBy('id')->lockForUpdate()->get();
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

    public function commitments(int $tenantId, array $lotIds, bool $lock = false, ?int $ownedItemId = null,
        ?string $kind = null, ?string $sourceType = null, ?string $sourceCode = null): Collection
    {
        // Filter before loading/locking; retain exhausted rows only for the requested source's history.
        return OwnershipCommitment::query()->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
            ->when($ownedItemId !== null, fn ($query) => $query->where('owned_item_id', $ownedItemId))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($sourceType !== null, fn ($query) => $query->where('source_type', $sourceType))
            ->when($sourceCode !== null, fn ($query) => $query->where('source_code', $sourceCode))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function pieceCommitments(int $tenantId, array $membershipIds, bool $lock = false): Collection
    {
        // Active piece holds are independent of the item's aggregate quantity.
        return OwnershipPieceCommitment::query()->where('tenant_id', $tenantId)->whereIn('ownership_piece_membership_id', $membershipIds)
            ->whereNull('ended_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function lockedBalanceParts(int $tenantId, int $itemId, string $cutoff): array
    {
        // Use current locking rows after the aggregate anchor, even with an older repeatable-read snapshot.
        $header = DB::table('ownership_monthly_checkpoints')->where('tenant_id', $tenantId)->where('owned_item_id', $itemId)
            ->whereNotNull('completed_at')->where('cutoff_at', '<=', $cutoff)->orderByDesc('month')->lockForUpdate()->first();
        $base = $header === null ? collect() : DB::table('ownership_monthly_checkpoint_balances')
            ->where('tenant_id', $tenantId)->where('ownership_monthly_checkpoint_id', $header->id)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('acquisition_lot_id');
        $tail = DB::table('ownership_movements')->where('tenant_id', $tenantId)->where('owned_item_id', $itemId)
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

    private function persistState(int $tenantId, \Illuminate\Database\Eloquent\Model $model, array $data, bool $openOnly = false): bool
    {
        // Narrow public helpers supply fixed columns; every write includes tenant and primary-key scope.
        $updated = $model->newQuery()->where('tenant_id', $tenantId)->whereKey($model->getKey())
            ->when($openOnly, fn ($query) => $query->whereNull('ended_at'))->update($data);
        if ($updated !== 1) return false;
        $model->fill($data)->syncOriginal();
        return true;
    }
}
