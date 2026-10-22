<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\InventoryService;
use App\Services\TableIdGenerationService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Auth, DB};

/** Exact source-bound quantity effects used when a caller applies a FIFO allocation. */
class QuantityService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private InventoryService $inventoryService,
        private LookupService $lookup, private BalanceService $balances, private SaleSourceService $sales,
        private TableIdGenerationService $codes) {}

    public function change(string $ownedCode, string $lotCode, mixed $quantity, string $sourceCode,
        array $pieceCodes, string $action, ?OwnershipPledgeSource $pledge = null): OwnershipResult
    {
        // Source, complete item anchors, aggregate, lots/commitments, then downstream custody locks.
        return DB::transaction(function () use ($ownedCode, $lotCode, $quantity, $sourceCode, $pieceCodes, $action, $pledge): OwnershipResult {
            $tenant = $this->resolveCurrentTenantId();
            $line = $pledge === null ? $this->sales->line($sourceCode) : null;
            $owner = $this->lookup->byCode($ownedCode);
            $item = $this->inventoryService->findForOwnershipByCode($owner->inventoryItem->code);
            $owner = $this->lookup->byInventoryId((int) $item->id, true);
            $lots = $this->repository->lockedLots($tenant, (int) $owner->id);
            $lot = $lots->firstWhere('code', $lotCode) ?? throw new TenantNotFound(__('ownership.lot_not_found'));
            $amount = InventoryQuantity::parse($quantity, $item->tracking_mode);
            $quantity = InventoryQuantity::format($amount);
            $this->validateSource($line, $pledge, $owner, $lot, $sourceCode, $quantity, $pieceCodes);
            $balance = $this->balances->locked((int) $owner->id, $lots)[$lot->id];
            $links = $this->repository->receiptLinks($tenant, [$lot->id], true);
            $members = $this->repository->currentMemberships($tenant, [$lot->id], true, ownedItemId: (int) $owner->id);
            $kind = $pledge === null ? 'SALE' : 'PLEDGE';
            $sourceType = $pledge === null ? 'SaleOrderLine' : 'BusinessLoanCollateral';
            // Reuse the existing query with source filters; unrelated sales/loans are not loaded or locked.
            $commitments = $this->repository->commitments($tenant, $lots->pluck('id')->all(), true,
                ownedItemId: (int) $owner->id, kind: $kind, sourceType: $sourceType, sourceCode: $sourceCode);
            $commitment = $commitments->where('acquisition_lot_id', $lot->id)->where('kind', $kind)
                ->where('source_type', $sourceType)->firstWhere('source_code', $sourceCode);
            $held = $this->repository->pieceCommitments($tenant, $members->pluck('id')->all(), true);
            if ($action === 'reserve' || $action === 'pledge') {
                $received = 0;
                foreach ($links as $link) $received += InventoryQuantity::parseBalance($link->remaining_owned_quantity);
                if ($amount > min($balance['available'], $received - $balance['reserved'] - $balance['pledged'])) {
                    throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
                }
                $stock = $this->inventoryService->ownershipStock($item, pieceCodes: $pieceCodes);
                $selected = $this->validatePieces($item->tracking_mode, $amount, $pieceCodes, $stock, $members, $held, null);
                $physical = array_sum(array_column($stock->locations, 'available'));
                if ($amount > $physical) throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
                $this->checkSourceCapacity($line, $commitments, $sourceCode, $amount);
                $commitment = $this->reserve($commitment, $owner, $lot, $kind, $sourceType, $sourceCode, $amount);
                foreach ($selected as $member) $this->repository->createPieceCommitment(['tenant_id' => $tenant,
                    'ownership_commitment_id' => $commitment->id, 'ownership_piece_membership_id' => $member->id]);
            } else {
                if ($commitment === null || $amount > InventoryQuantity::parseBalance($commitment->remaining_quantity)) {
                    throw new InvalidTenantRequest(__('ownership.commitment_insufficient'));
                }
                $stock = $this->inventoryService->ownershipStock($item, pieceCodes: $pieceCodes);
                $selected = $this->validatePieces($item->tracking_mode, $amount, $pieceCodes, $stock, $members, $held, $commitment);
                $this->requireSaved($this->repository->updateCommitment($tenant, $commitment, $commitment->quantity,
                    InventoryQuantity::format(InventoryQuantity::parseBalance($commitment->remaining_quantity) - $amount)));
                foreach ($held as $hold) {
                    if ($selected->has($hold->ownership_piece_membership_id)) {
                        $this->requireSaved($this->repository->endPieceCommitment($tenant, $hold, CarbonImmutable::now('UTC')));
                    }
                }
                if ($action === 'consume') $this->consumeLinks($links, $selected, $amount, $item->tracking_mode);
            }
            $consume = $action === 'consume';
            $delta = $action === 'release' || $consume ? '-'.$quantity : $quantity;
            $movement = $this->repository->createMovement($tenant, ['code' => $this->codes->generate('ownership_movements', CarbonImmutable::now()),
                'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id,
                'movement_type' => match ($action) {'reserve' => 'SALE_RESERVE', 'release' => 'SALE_RELEASE', 'consume' => 'SALE_CONSUME', 'pledge' => 'PLEDGE'},
                'quantity' => $quantity, 'owned_delta' => $consume ? '-'.$quantity : '0.000',
                'reserved_delta' => $kind === 'SALE' ? $delta : '0.000', 'pledged_delta' => $kind === 'PLEDGE' ? $delta : '0.000',
                'source_module' => $kind === 'SALE' ? 'SALES' : 'BUSINESS_LOAN', 'source_type' => $sourceType,
                'source_code' => $sourceCode, 'created_by' => Auth::guard('tenantuser')->id(), 'occurred_at' => CarbonImmutable::now('UTC')]);
            return new OwnershipResult($owner->code, $this->repository->advanceVersion($owner), [$lot->code], [$movement->code], [], $pieceCodes);
        });
    }

    private function validateSource($line, ?OwnershipPledgeSource $pledge, $owner, $lot, string $sourceCode, string $quantity, array $codes): void
    {
        // Trusted pledge context is supplied by its owning workflow; no public bypass is exposed.
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

    private function validatePieces(string $mode, int $amount, array $codes, $stock, $members, $held, $commitment)
    {
        // Consumption can use its own physical reservation; it must still own the exact source-bound pieces.
        if ($mode !== 'SERIALIZED') {
            if ($codes !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            return collect();
        }
        if (count($codes) * 1000 !== $amount || count(array_unique($codes)) !== count($codes)
            || count($stock->pieces) !== count($codes)) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
        $byPiece = $members->keyBy('inventory_serialized_item_id');
        $holds = $held->keyBy('ownership_piece_membership_id');
        $selected = collect();
        foreach ($stock->pieces as $piece) {
            $member = $byPiece->get($piece['id']);
            $hold = $member === null ? null : $holds->get($member->id);
            if ($member === null || ($commitment === null && (! $piece['available'] || $hold !== null))
                || ($commitment !== null && ($hold === null || $hold->ownership_commitment_id !== $commitment->id))) {
                throw new InvalidTenantRequest(__('ownership.receipt_link_required'));
            }
            $selected->put($member->id, $member);
        }
        return $selected;
    }

    private function checkSourceCapacity($line, $commitments, string $source, int $amount): void
    {
        // A sale line cannot reserve more than its requested quantity across all cost layers.
        if ($line === null) return;
        $total = $amount;
        foreach ($commitments->where('kind', 'SALE')->where('source_code', $source) as $row) {
            $total += InventoryQuantity::parseBalance($row->quantity);
        }
        if ($total > InventoryQuantity::parse($line->ordered_quantity)) throw new InvalidTenantRequest(__('ownership.commitment_insufficient'));
    }

    private function reserve($commitment, $owner, $lot, string $kind, string $type, string $source, int $amount)
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
        foreach ($links as $link) {
            $take = $mode === 'SERIALIZED' ? $selected->where('ownership_receipt_link_id', $link->id)->count() * 1000
                : min($amount, InventoryQuantity::parseBalance($link->remaining_owned_quantity));
            if ($take === 0) continue;
            $this->requireSaved($this->repository->updateReceiptRemaining($this->resolveCurrentTenantId(), $link,
                InventoryQuantity::format(InventoryQuantity::parseBalance($link->remaining_owned_quantity) - $take)));
            $amount -= $take;
        }
        if ($amount !== 0) throw new InvalidTenantRequest(__('ownership.receipt_link_required'));
        foreach ($selected as $member) {
            $this->requireSaved($this->repository->endMembership($this->resolveCurrentTenantId(), $member, CarbonImmutable::now('UTC')));
        }
    }

    private function requireSaved(bool $saved): void
    {
        // A missing/stale/foreign target must roll back the caller rather than leave a partial mutation.
        if (! $saved) throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
    }
}
