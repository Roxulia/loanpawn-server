<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipMutationResult;
use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipQuantityState;
use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipReturnSelection;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\InventoryService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Exact source-bound quantity effects used when a caller applies a FIFO allocation. */
class QuantityService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private InventoryService $inventoryService,
        private LookupService $lookup, private BalanceService $balances, private SaleSourceService $sales,
        private TableIdGenerationService $codes, private QuantityValidationService $validation, private OperationService $operations, private MovementService $movements) {}

    public function applyOwnershipQuantityAction(string $ownedCode, string $lotCode, mixed $quantity, string $sourceCode,
        array $pieceCodes, string $action, ?OwnershipPledgeSource $pledge = null, ?string $idempotencyKey = null): OwnershipResult
    {
        // Retain the caller transaction while sharing preparation, validation and piece selection.
        return $this->operations->runAtomicOwnershipOperation('ownership.quantity.'.$action, $idempotencyKey,
            compact('ownedCode', 'lotCode', 'quantity', 'sourceCode', 'pieceCodes', 'action'),
            function () use ($ownedCode, $lotCode, $quantity, $sourceCode, $pieceCodes, $action, $pledge): OwnershipMutationResult {
                $state = $this->prepareQuantityState($ownedCode, $lotCode, $quantity, $sourceCode, $pieceCodes, $pledge, $action);
                $isReservation = $action === 'reserve' || $action === 'pledge';
                $this->validation->validateQuantityActionBalance($action, $state->amount, $state->balance, $state->commitment?->remaining_quantity);
                if ($isReservation) {
                    $this->validateReceivedBacking($state);
                } else {
                    $this->validation->validateSourceCommitment($state->commitment, $state->owner, $state->lot,
                        $state->kind, $state->sourceType, $state->sourceCode);
                }

                // Both branches use one authoritative custody read and one exact piece-selection check.
                $stock = $this->inventoryService->ownershipStock($state->item, pieceCodes: $pieceCodes);
                $selected = $this->validation->selectEligibleOwnershipPieces($state->item->tracking_mode, $state->amount, $pieceCodes,
                    $stock, $state->members, $state->pieceHolds, $isReservation ? null : $state->commitment,
                    requireCustody: $action === 'consume');
                if ($isReservation) {
                    $this->reserveOwnershipQuantity($state, $selected, array_sum(array_column($stock->locations, 'available')));
                } else {
                    $this->releaseOrConsumeOwnershipQuantity($state, $selected, $action);
                }
                return $this->recordOwnershipQuantityAction($state, $pieceCodes, $action);
            }, 200, 'ownership.quantity_changed');
    }

    public function consumePurchaseReturnReceiptBacking(OwnedItem $owner, AcquisitionLot $lot, OwnershipReturnSelection $selection): void
    {
        // Reuse the transaction's validated membership selection instead of repeating custody/membership reads.
        $tenant = $this->resolveCurrentTenantId();
        if ($selection->tenantId !== $tenant || $selection->ownedItemId !== (int) $owner->id
            || $selection->lotId !== (int) $lot->id || $selection->sourceType !== 'PurchaseReturnLine') {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
        $item = $owner->inventoryItem;
        // Preserve the exact selected-count guard when a source authorizes more units than this request.
        if ($item->tracking_mode === 'SERIALIZED' && $selection->members->count() * 1000 !== $selection->amount) {
            throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
        }
        $links = $this->repository->receiptLinks($tenant, [$lot->id], true);
        $this->consumeLinks($links, $selection->members, $selection->amount, $item->tracking_mode);
    }

    private function prepareQuantityState(string $ownedCode, string $lotCode, mixed $quantity, string $sourceCode,
        array $pieceCodes, ?OwnershipPledgeSource $pledge, string $action): OwnershipQuantityState
    {
        // Lock the business source before Inventory, then re-read Ownership after its shared identity anchor.
        $tenant = $this->resolveCurrentTenantId();
        $kind = $this->resolveQuantityActionKind($action, $pledge);
        $line = $kind === 'SALE' ? $this->sales->line($sourceCode) : null;
        $reference = $this->lookup->byCode($ownedCode);
        $item = $this->inventoryService->findForOwnershipById((int) $reference->inventory_item_id);
        $owner = $this->lookup->byInventoryId((int) $item->id, true);
        $lot = $this->repository->lotByCode($tenant, (int) $owner->id, $lotCode, true)
            ?? throw new TenantNotFound(__('ownership.lot_not_found'));
        $amount = InventoryQuantity::parse($quantity, $item->tracking_mode);
        $quantity = InventoryQuantity::format($amount);
        if ($line !== null || $pledge !== null) {
            $this->validation->validateQuantityActionSource($line, $pledge, $owner, $lot, $sourceCode, $quantity, $pieceCodes);
        }
        $balance = $this->balances->locked((int) $owner->id, [$lot])[$lot->id];
        $links = $this->repository->receiptLinks($tenant, [$lot->id], true);
        // Fungible items have no serialized state to load or lock.
        $members = $item->tracking_mode === 'SERIALIZED'
            ? $this->repository->currentMemberships($tenant, [$lot->id], true, ownedItemId: (int) $owner->id)
            : collect();
        $sourceType = $kind === 'SALE' ? 'SaleOrderLine' : 'BusinessLoanCollateral';

        // The repository already filters kind/type/source; only select the target lot in memory.
        $commitments = $this->repository->commitments($tenant, $action === 'reserve' ? null : [$lot->id], true,
            ownedItemId: (int) $owner->id, kind: $kind, sourceType: $sourceType, sourceCode: $sourceCode);
        $commitment = $commitments->firstWhere('acquisition_lot_id', $lot->id);
        $holds = $members->isEmpty() ? collect()
            : $this->repository->pieceCommitments($tenant, $members->pluck('id')->all(), true);
        return new OwnershipQuantityState(
            tenantId: $tenant, owner: $owner, item: $item, lot: $lot, amount: $amount, quantity: $quantity,
            balance: $balance, links: $links, members: $members, commitments: $commitments, commitment: $commitment,
            pieceHolds: $holds, saleLine: $line, kind: $kind, sourceType: $sourceType, sourceCode: $sourceCode,
        );
    }

    private function resolveQuantityActionKind(string $action, ?OwnershipPledgeSource $pledge): string
    {
        // The action decides the ledger dimension; incompatible source contexts never reach writes.
        $kind = match ($action) {
            'reserve', 'release', 'consume' => 'SALE',
            'pledge', 'unpledge', 'forfeit' => 'PLEDGE',
            default => throw new InvalidTenantRequest(__('ownership.commitment_source_invalid')),
        };
        if (($action === 'pledge' && $pledge === null) || ($pledge !== null && $action !== 'pledge')) {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
        return $kind;
    }

    private function validateReceivedBacking(OwnershipQuantityState $state): void
    {
        // Free Ownership capacity was checked already; now check the distinct received-backing constraint.
        $received = 0;
        foreach ($state->links as $link) $received += InventoryQuantity::parseBalance($link->remaining_owned_quantity);
        if ($state->amount > $received - $state->balance['reserved'] - $state->balance['pledged']) {
            throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
        }
    }

    private function reserveOwnershipQuantity(OwnershipQuantityState $state, Collection $selected, int $physicalAvailable): void
    {
        // Reserve only physically available units within the existing source capacity rule.
        if ($state->amount > $physicalAvailable) throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
        $this->validation->validateSaleLineReservationCapacity($state->saleLine, $state->commitments, $state->sourceCode, $state->amount);
        $commitment = $this->increaseSourceCommitment($state->commitment, $state->owner, $state->lot,
            $state->kind, $state->sourceType, $state->sourceCode, $state->amount);
        $this->repository->createSourcePieceHolds($state->tenantId, (int) $commitment->id, $selected->keys()->all(), CarbonImmutable::now('UTC'));
    }

    private function releaseOrConsumeOwnershipQuantity(OwnershipQuantityState $state, Collection $selected, string $action): void
    {
        // Identity and remaining capacity were validated once before entering this mutation helper.
        $commitment = $state->commitment;
        $remaining = InventoryQuantity::parseBalance($commitment->remaining_quantity) - $state->amount;
        // Released sale capacity is reusable, whereas consumed quantity continues to count against the line.
        $sourceQuantity = $action === 'release'
            ? InventoryQuantity::format(InventoryQuantity::parseBalance($commitment->quantity) - $state->amount)
            : $commitment->quantity;
        $this->requireSaved($this->repository->updateCommitment($state->tenantId, $commitment,
            $sourceQuantity, InventoryQuantity::format($remaining)));
        $endedAt = CarbonImmutable::now('UTC');
        $holdIds = $state->pieceHolds->filter(fn ($hold) => $selected->has($hold->ownership_piece_membership_id))->pluck('id')->all();
        $this->requireSaved($this->repository->endSourcePieceHolds($state->tenantId, (int) $commitment->id, $holdIds, $endedAt) === count($holdIds));
        if (in_array($action, ['consume', 'forfeit'], true)) $this->consumeLinks($state->links, $selected, $state->amount, $state->item->tracking_mode);
    }

    private function recordOwnershipQuantityAction(OwnershipQuantityState $state, array $pieceCodes, string $action): OwnershipMutationResult
    {
        // Append one exact transition and advance the version once for the complete logical action.
        $occurredAt = CarbonImmutable::now('UTC');
        $movement = $this->movements->appendOwnershipMovement($this->movements->buildOwnershipQuantityTransition($action, $state->quantity, [
            'code' => $this->codes->generate('ownership_movements', $occurredAt),
            'owned_item_id' => $state->owner->id, 'acquisition_lot_id' => $state->lot->id,
            'source_module' => $state->kind === 'SALE' ? 'SALES' : 'BUSINESS_LOAN', 'source_type' => $state->sourceType,
            'source_code' => $state->sourceCode, 'created_by' => Auth::guard('tenantuser')->id(), 'occurred_at' => $occurredAt]));
        $response = new OwnershipResult($state->owner->code, $this->repository->advanceVersion($state->owner),
            [$state->lot->code], [$movement->code], [], $pieceCodes);
        return new OwnershipMutationResult($response, $state->owner);
    }

    private function increaseSourceCommitment($commitment, $owner, $lot, string $kind, string $type, string $source, int $amount)
    {
        // Repeated allocations for one source/location split accumulate on the same source-bound record.
        if ($commitment !== null) {
            $this->requireSaved($this->repository->updateCommitment($this->resolveCurrentTenantId(), $commitment,
                InventoryQuantity::format(InventoryQuantity::parseBalance($commitment->quantity) + $amount),
                InventoryQuantity::format(InventoryQuantity::parseBalance($commitment->remaining_quantity) + $amount)));
            return $commitment;
        }
        return $this->repository->createCommitment(['tenant_id' => $this->resolveCurrentTenantId(), 'owned_item_id' => $owner->id,
            'acquisition_lot_id' => $lot->id, 'kind' => $kind, 'source_type' => $type, 'source_code' => $source,
            'quantity' => InventoryQuantity::format($amount), 'remaining_quantity' => InventoryQuantity::format($amount)]);
    }

    private function consumeLinks($links, $selected, int $amount, string $mode): void
    {
        // Reduce original received backing; close membership episodes without deleting their history.
        $pieceCounts = $mode === 'SERIALIZED' ? $selected->countBy('ownership_receipt_link_id') : collect();
        foreach ($links as $link) {
            $take = $mode === 'SERIALIZED' ? $pieceCounts->get($link->id, 0) * 1000
                : min($amount, InventoryQuantity::parseBalance($link->remaining_owned_quantity));
            if ($take === 0) continue;
            $this->requireSaved($this->repository->updateReceiptRemaining($this->resolveCurrentTenantId(), $link,
                InventoryQuantity::format(InventoryQuantity::parseBalance($link->remaining_owned_quantity) - $take)));
            $amount -= $take;
        }
        if ($amount !== 0) throw new InvalidTenantRequest(__('ownership.receipt_link_required'));
        if ($selected->isNotEmpty()) {
            $lotId = (int) $selected->first()->acquisition_lot_id;
            $this->requireSaved($this->repository->endLotPieceMemberships($this->resolveCurrentTenantId(), $lotId,
                $selected->keys()->all(), CarbonImmutable::now('UTC')) === $selected->count());
        }
    }

    private function requireSaved(bool $saved): void
    {
        // A missing/stale/foreign target must roll back the caller rather than leave a partial mutation.
        if (! $saved) throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
    }
}
