<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use App\Exceptions\InvalidTenantRequest;
use App\Services\BaseTenantService;
use App\Support\InventoryQuantity;

/** Shared locked-state validators; orchestration retains transaction and mutation ownership. */
class QuantityValidationService extends BaseTenantService
{
    public function validateSourceCommitment($commitment, $owner, $lot, string $kind, string $type, string $code): void
    {
        // Release and forfeiture can use only this exact tenant/item/lot/kind/source commitment.
        if ($commitment === null || (int) $commitment->tenant_id !== $this->resolveCurrentTenantId()
            || (int) $commitment->owned_item_id !== (int) $owner->id
            || (int) $commitment->acquisition_lot_id !== (int) $lot->id
            || $commitment->kind !== $kind || $commitment->source_type !== $type || $commitment->source_code !== $code) {
            throw new InvalidTenantRequest(__('ownership.commitment_insufficient'));
        }
    }

    public function validateQuantityActionBalance(string $action, int $amount, array $balance, ?string $sourceRemaining = null): void
    {
        // Reservation requires free ownership; release/consume/forfeit require this source's remaining hold.
        if (in_array($action, ['reserve', 'pledge'], true)) {
            if ($amount > $balance['available']) throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
            return;
        }
        if (! in_array($action, ['release', 'consume', 'unpledge', 'forfeit'], true)
            || $sourceRemaining === null || $amount > InventoryQuantity::parseBalance($sourceRemaining)) {
            throw new InvalidTenantRequest(__('ownership.commitment_insufficient'));
        }
        // Forfeiture consumes pledged ownership even when free availability is zero.
        $held = in_array($action, ['unpledge', 'forfeit'], true) ? $balance['pledged'] : $balance['reserved'];
        if ($amount > $held || (in_array($action, ['consume', 'forfeit'], true) && $amount > $balance['owned'])) {
            throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
        }
    }

    public function validateQuantityActionSource($line, ?OwnershipPledgeSource $pledge, $owner, $lot, string $sourceCode, string $quantity, array $codes): void
    {
        // Trusted pledge context is supplied by its owning workflow; no public bypass is exposed.
        $tenant = $this->resolveCurrentTenantId();
        if (($line === null && $pledge === null) || (int) $owner->tenant_id !== $tenant
            || (int) $lot->tenant_id !== $tenant || (int) $lot->owned_item_id !== (int) $owner->id) {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
        if ($line !== null && ((int) $line->inventory_item_id !== (int) $owner->inventory_item_id
            || (int) $line->owned_item_id !== (int) $owner->id || $line->order->currency_code !== $lot->currency_code)) {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
        if ($pledge !== null && ($pledge->tenantId !== $this->resolveCurrentTenantId() || $pledge->sourceType !== 'BusinessLoanCollateral'
            || $pledge->sourceCode !== $sourceCode || $pledge->ownedItemCode !== $owner->code || $pledge->lotCode !== $lot->code
            || $pledge->quantity !== $quantity || $pledge->currencyCode !== $lot->currency_code || $pledge->serializedItemCodes !== $codes)) {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
    }

    public function selectEligibleOwnershipPieces(string $mode, int $amount, array $codes, $stock, $members, $held, $commitment, bool $requireCustody = false)
    {
        // Consumption can use its own physical reservation; it must still own the exact source-bound pieces.
        if ($mode !== 'SERIALIZED') {
            if ($codes !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            return collect();
        }
        $this->validateSerializedSelectionShape($amount, $codes, $stock->pieces);
        $byPiece = $members->keyBy('inventory_serialized_item_id');
        $holds = $held->keyBy('ownership_piece_membership_id');
        $selected = collect();
        foreach ($stock->pieces as $piece) {
            $member = $byPiece->get($piece['id']);
            if ($member === null) $this->rejectIneligibleOwnershipPiece();
            if ($requireCustody && $piece['location_id'] === null) $this->rejectIneligibleOwnershipPiece();
            $hold = $holds->get($member->id);
            if ($commitment === null) {
                $this->validateUncommittedPieceAvailability($piece, $hold);
            } else {
                $this->validatePieceCommitmentOwner($hold, (int) $commitment->id);
            }
            $selected->put($member->id, $member);
        }
        return $selected;
    }

    public function validateSaleLineReservationCapacity($line, $commitments, string $source, int $amount): void
    {
        // A sale line cannot reserve more than its requested quantity across all cost layers.
        if ($line === null) return;
        $total = $amount;
        foreach ($commitments->where('kind', 'SALE')->where('source_code', $source) as $row) {
            $total += InventoryQuantity::parseBalance($row->quantity);
        }
        if ($total > InventoryQuantity::parse($line->ordered_quantity)) throw new InvalidTenantRequest(__('ownership.commitment_insufficient'));
    }

    private function validateSerializedSelectionShape(int $amount, array $codes, array $pieces): void
    {
        // One distinct resolved identity must exist for each requested whole unit.
        if (count($codes) * 1000 !== $amount || count(array_unique($codes)) !== count($codes)
            || count($pieces) !== count($codes)) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
    }

    private function validateUncommittedPieceAvailability(array $piece, $hold): void
    {
        // A new reservation cannot borrow an unavailable piece or another operation's hold.
        if (! $piece['available'] || $hold !== null) $this->rejectIneligibleOwnershipPiece();
    }

    private function validatePieceCommitmentOwner($hold, int $commitmentId): void
    {
        // Releasing or consuming requires this exact source's existing piece hold.
        if ($hold === null || (int) $hold->ownership_commitment_id !== $commitmentId) $this->rejectIneligibleOwnershipPiece();
    }

    private function rejectIneligibleOwnershipPiece(): never
    {
        // Keep existing localized rejection behavior while making each failed condition readable.
        throw new InvalidTenantRequest(__('ownership.receipt_link_required'));
    }
}
