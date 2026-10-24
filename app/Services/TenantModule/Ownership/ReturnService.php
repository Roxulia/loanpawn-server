<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\{OwnershipMutationResult, OwnershipReturnSelection};
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\InventoryService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;

/** Existing internal return compatibility only; business callers own stock, refund and financial effects. */
class ReturnService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private ReturnValidationService $validation,
        private InventoryService $inventoryService, private LookupService $lookup, private BalanceService $balances,
        private QuantityService $quantities, private MovementService $movements, private OperationService $operations,
        private TableIdGenerationService $codes) {}

    public function applyOriginalLotReturn(string $ownerCode, string $lotCode, mixed $quantity, string $sourceCode,
        string $type, ?array $pieceCodes = null, ?string $key = null): OwnershipResult
    {
        // Resolve original business provenance before shared anchors; one wrapper owns audit/completion.
        return $this->operations->runAtomicOwnershipOperation('ownership.return.'.$type, $key,
            compact('ownerCode', 'lotCode', 'quantity', 'sourceCode', 'pieceCodes'),
            function () use ($ownerCode, $lotCode, $quantity, $sourceCode, $type, $pieceCodes): OwnershipMutationResult {
                $source = $this->validation->resolveReturnSource($type, $sourceCode);
                $reference = $this->lookup->byCode($ownerCode);
                $item = $this->inventoryService->findForOwnershipById((int) $reference->inventory_item_id);
                $owner = $this->lookup->byInventoryId((int) $item->id, true);
                $lot = $this->repository->lotByCode($this->resolveCurrentTenantId(), (int) $owner->id, $lotCode, true)
                    ?? throw new TenantNotFound(__('ownership.lot_not_found'));
                $selection = $this->validation->validateReturnEligibility($source, $owner, $lot, $type, $quantity, $item->tracking_mode);
                if ($pieceCodes !== null && $pieceCodes !== $selection->pieceCodes) $this->rejectInvalidReturn();
                if ($type === 'PurchaseReturnLine') {
                    $balance = $this->balances->locked((int) $owner->id, [$lot])[$lot->id];
                    if ($selection->amount > $balance['available']) $this->rejectInvalidReturn();
                    $this->quantities->consumePurchaseReturnReceiptBacking($owner, $lot, $selection);
                } else {
                    $this->restoreOriginalReceiptBacking($owner, $lot, $selection);
                }
                return $this->recordOriginalLotReturn($owner, $lot, $selection, $sourceCode);
            }, 200, 'ownership.returned');
    }

    private function restoreOriginalReceiptBacking(OwnedItem $owner, AcquisitionLot $lot, OwnershipReturnSelection $selection): void
    {
        // Reuse original receipt allocations and create new membership episodes, never a second acquisition.
        $tenant = $selection->tenantId;
        $links = $this->repository->receiptLinks($tenant, [$lot->id], true);
        $pieces = $selection->pieces;
        $history = $this->repository->latestLotMembershipsForPieces($tenant, (int) $owner->id, (int) $lot->id, array_column($pieces, 'id'));
        $byLink = collect();
        foreach ($pieces as $piece) {
            $episode = $history->get($piece['id']);
            if ($episode === null || $episode->ended_at === null || $piece['location_id'] === null) $this->rejectInvalidReturn();
            $byLink->put($episode->ownership_receipt_link_id, $byLink->get($episode->ownership_receipt_link_id, 0) + 1000);
        }
        $remaining = $selection->amount;
        foreach ($links as $link) {
            $room = InventoryQuantity::parseBalance($link->linked_quantity) - InventoryQuantity::parseBalance($link->remaining_owned_quantity);
            $take = $pieces === [] ? min($remaining, $room) : $byLink->get($link->id, 0);
            if ($take > $room) $this->rejectInvalidReturn();
            if ($take === 0) continue;
            if (! $this->repository->updateReceiptRemaining($tenant, $link,
                InventoryQuantity::format(InventoryQuantity::parseBalance($link->remaining_owned_quantity) + $take))) $this->rejectInvalidReturn();
            $remaining -= $take;
        }
        if ($remaining !== 0) $this->rejectInvalidReturn();
        $now = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($pieces as $piece) {
            $rows[] = ['tenant_id' => $tenant, 'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id,
                'ownership_receipt_link_id' => $history->get($piece['id'])->ownership_receipt_link_id,
                'inventory_serialized_item_id' => $piece['id'], 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 500) { $this->repository->insertOwnershipMemberships($rows); $rows = []; }
        }
        if ($rows !== []) $this->repository->insertOwnershipMemberships($rows);
    }

    private function recordOriginalLotReturn(OwnedItem $owner, AcquisitionLot $lot, OwnershipReturnSelection $selection, string $sourceCode): OwnershipMutationResult
    {
        // Historical currency/cost snapshots remain unchanged, including now-inactive currencies.
        $quantity = InventoryQuantity::format($selection->amount);
        $purchaseReturn = $selection->sourceType === 'PurchaseReturnLine';
        $movement = $this->movements->appendOwnershipMovement([
            'code' => $this->codes->generate('ownership_movements', CarbonImmutable::now('UTC')),
            'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id,
            'movement_type' => $purchaseReturn ? 'PURCHASE_RETURN' : 'SALE_RETURN', 'quantity' => $quantity,
            'owned_delta' => $purchaseReturn ? '-'.$quantity : $quantity, 'reserved_delta' => '0.000', 'pledged_delta' => '0.000',
            'source_module' => $purchaseReturn ? 'PURCHASING' : 'SALES', 'source_type' => $selection->sourceType, 'source_code' => $sourceCode]);
        return new OwnershipMutationResult(new OwnershipResult($owner->code, $this->repository->advanceVersion($owner),
            [$lot->code], [$movement->code], [], $selection->pieceCodes), $owner);
    }

    private function rejectInvalidReturn(): never
    {
        throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
    }
}
