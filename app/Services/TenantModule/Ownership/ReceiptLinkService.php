<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\{OwnershipMutationResult, OwnershipReceiptState};
use App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{AlreadyUpdatedException, TenantNotFound};
use App\Models\OwnershipModule\OwnershipReceiptLink;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\InventoryService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;

/** Explicit and trusted receipt linking share one source-safe, history-preserving persistence path. */
class ReceiptLinkService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private LookupService $lookup,
        private InventoryService $inventoryService, private PurchaseSourceService $purchases,
        private OperationService $operations, private TableIdGenerationService $codes, private ReceiptValidationService $validation) {}

    public function link(string $ownedCode, string $lotCode, OwnershipReceiptLinkRequest $request, bool $publicReplay = false): OwnershipResult
    {
        // Replay is checked before version validation; normal retries return the original typed result.
        return $this->operations->runAtomicOwnershipOperation('ownership.receipt_link', $request->idempotencyKey,
            ['owned_item_code' => $ownedCode, 'lot_code' => $lotCode] + $request->toArray(),
            function (?int $recordId) use ($ownedCode, $lotCode, $request): OwnershipMutationResult {
                $state = $this->resolveRequestedReceiptState($ownedCode, $lotCode, $request->purchaseReceiptLineCode);
                if ((int) $state->owner->update_key !== $request->updateKey) throw new AlreadyUpdatedException(null);
                return $this->persistReceiptOwnershipLink($state, $request->quantity, $request->serializedItemCodes, $recordId);
            }, 200, 'ownership.receipt_linked', $publicReplay);
    }

    public function linkPurchaseReceipt(string $receiptCode): OwnershipResult
    {
        // One independently repeatable source command; no client paid flags or randomly generated nested keys.
        return $this->operations->runAtomicOwnershipOperation('ownership.receipt_auto_link', 'receipt:'.hash('sha256', $receiptCode),
            ['purchase_receipt_line_code' => $receiptCode], function (?int $recordId) use ($receiptCode): OwnershipMutationResult {
                $receipt = $this->purchases->receipt($receiptCode, true);
                $tenant = $this->resolveCurrentTenantId();
                $item = $this->inventoryService->findForOwnershipById((int) $receipt->inventory_item_id);
                $owner = $this->lookup->byInventoryId((int) $item->id, true);
                $lot = $this->repository->findBySourceCode($tenant, 'PURCHASING', 'PurchaseOrderLine', $receipt->orderLine->code, true)
                    ?? throw new TenantNotFound(__('ownership.lot_not_found'));
                $state = new OwnershipReceiptState($tenant, $receipt, $item, $owner, $lot);
                return $this->persistReceiptOwnershipLink($state, $receipt->received_quantity, $receipt->serialized_item_codes ?? [], $recordId);
            }, 200, 'ownership.receipt_linked');
    }

    private function resolveRequestedReceiptState(string $ownedCode, string $lotCode, string $receiptCode): OwnershipReceiptState
    {
        // Source, Inventory identity, aggregate, then the selected lot; never load every historical lot.
        $tenant = $this->resolveCurrentTenantId();
        $receipt = $this->purchases->receipt($receiptCode, true);
        $reference = $this->lookup->byCode($ownedCode);
        $item = $this->inventoryService->findForOwnershipById((int) $reference->inventory_item_id);
        $owner = $this->lookup->byInventoryId((int) $item->id, true);
        $lot = $this->repository->lotByCode($tenant, (int) $owner->id, $lotCode, true)
            ?? throw new TenantNotFound(__('ownership.lot_not_found'));
        return new OwnershipReceiptState($tenant, $receipt, $item, $owner, $lot);
    }

    private function persistReceiptOwnershipLink(OwnershipReceiptState $state, mixed $quantity, array $pieceCodes, ?int $recordId): OwnershipMutationResult
    {
        // Source uniqueness binds one purchase line to one acquisition lot; allocations stay on that original lot.
        $this->validation->validateReceiptLotAssociation($state->receipt, $state->lot, $state->item);
        $amount = InventoryQuantity::parse($quantity, $state->item->tracking_mode);
        $links = $this->repository->receiptLinks($state->tenantId, [$state->lot->id], true);
        $this->validation->validateReceiptLinkCapacity($state->receipt, $state->lot, $links, $amount);
        $stock = $this->inventoryService->ownershipStock($state->item, pieceCodes: $pieceCodes);
        $members = $this->repository->currentMembershipsForPieces($state->tenantId, array_column($stock->pieces, 'id'));
        $this->validation->validateReceiptPieceSelection($state->item->tracking_mode, $amount, $pieceCodes,
            $state->receipt->serialized_item_codes ?? [], $stock->pieces, $members);
        $link = $this->createReceiptOwnershipAllocation($state, InventoryQuantity::format($amount), $recordId);
        $this->createReceiptPieceMemberships($state, $link, $stock->pieces);
        $response = new OwnershipResult($state->owner->code, $this->repository->advanceVersion($state->owner),
            [$state->lot->code], [], [$link->code], $pieceCodes);
        return new OwnershipMutationResult($response, $state->owner);
    }

    private function createReceiptOwnershipAllocation(OwnershipReceiptState $state, string $quantity, ?int $recordId): OwnershipReceiptLink
    {
        // Original allocation never changes when goods are later sold, returned or pledged.
        return $this->repository->createReceiptLink(['tenant_id' => $state->tenantId,
            'code' => $this->codes->generate('ownership_receipt_links', CarbonImmutable::now('UTC')),
            'owned_item_id' => $state->owner->id, 'acquisition_lot_id' => $state->lot->id,
            'purchase_receipt_line_id' => $state->receipt->id, 'linked_quantity' => $quantity,
            'remaining_owned_quantity' => $quantity, 'idempotency_record_id' => $recordId]);
    }

    private function createReceiptPieceMemberships(OwnershipReceiptState $state, OwnershipReceiptLink $link, array $pieces): void
    {
        // Bound temporary rows and INSERT bindings by batch size, instead of one query for each piece.
        $now = CarbonImmutable::now('UTC');
        for ($offset = 0; $offset < count($pieces); $offset += 500) {
            $rows = [];
            foreach (array_slice($pieces, $offset, 500) as $piece) {
                $rows[] = ['tenant_id' => $state->tenantId, 'owned_item_id' => $state->owner->id,
                    'acquisition_lot_id' => $state->lot->id, 'ownership_receipt_link_id' => $link->id,
                    'inventory_serialized_item_id' => $piece['id'], 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            $this->repository->insertOwnershipMemberships($rows);
        }
    }
}
