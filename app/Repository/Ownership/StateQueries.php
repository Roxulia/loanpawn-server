<?php

namespace App\Repository\Ownership;

use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipReceiptLink, OwnershipPieceMembership, OwnershipCommitment, OwnershipPieceCommitment};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

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

    public function currentMemberships(int $tenantId, array $lotIds, bool $lock = false): Collection
    {
        // Only open episodes authorize current use; historical episodes remain retained.
        return OwnershipPieceMembership::query()->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
            ->whereNull('ended_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function commitments(int $tenantId, array $lotIds, bool $lock = false): Collection
    {
        // Include exhausted sources for duplicate/source-bound validation without rereading all movements.
        return OwnershipCommitment::query()->where('tenant_id', $tenantId)->whereIn('acquisition_lot_id', $lotIds)
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

    public function updateState(\Illuminate\Database\Eloquent\Model $model, array $data): void
    {
        // Mutations target already resolved/locked Ownership models only.
        $model->update($data);
    }

    public function advanceVersion(OwnedItem $item): int
    {
        // The caller advances once for a logical mutation; read-only allocation never invokes this.
        $item->increment('update_key');
        return (int) $item->update_key;
    }
}
