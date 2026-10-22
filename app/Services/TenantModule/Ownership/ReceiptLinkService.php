<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{AlreadyUpdatedException, InvalidTenantRequest, TenantNotFound};
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\{InventoryService, TenantIdempotencyService};
use App\Services\TableIdGenerationService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Link existing received custody to an immutable acquisition without acquiring twice. */
class ReceiptLinkService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private LookupService $lookup,
        private InventoryService $inventoryService, private PurchaseSourceService $purchases,
        private TenantIdempotencyService $idempotency, private TableIdGenerationService $codes) {}

    public function link(string $ownedCode, string $lotCode, OwnershipReceiptLinkRequest $request): OwnershipResult
    {
        // Replay, linking, version and audit share the caller's transaction and roll back together.
        return DB::transaction(function () use ($ownedCode, $lotCode, $request): OwnershipResult {
            $record = $this->idempotency->reserve('ownership.receipt_link', $request->idempotencyKey,
                ['owned_item_code' => $ownedCode, 'lot_code' => $lotCode] + $request->toArray());
            if ($this->idempotency->isReplay($record)) {
                $data = $record->response_body;
                return new OwnershipResult($data['owned_item_code'], $data['update_key'], $data['lot_codes'],
                    $data['movement_codes'], $data['receipt_link_codes'], $data['serialized_item_codes']);
            }
            $tenant = $this->resolveCurrentTenantId();
            $receipt = $this->purchases->receipt($request->purchaseReceiptLineCode, true);
            $owner = $this->lookup->byCode($ownedCode);
            $item = $this->inventoryService->findForOwnershipByCode($owner->inventoryItem->code);
            $owner = $this->lookup->byInventoryId((int) $item->id, true);
            $lots = $this->repository->lockedLots($tenant, (int) $owner->id);
            $lot = $lots->firstWhere('code', $lotCode) ?? throw new TenantNotFound(__('ownership.lot_not_found'));
            if ((int) $owner->update_key !== $request->updateKey) throw new AlreadyUpdatedException(null);
            if ($lot->source_module !== 'PURCHASING' || $lot->source_type !== 'PurchaseOrderLine'
                || $receipt->orderLine?->code !== $lot->source_code
                || (int) $receipt->inventory_item_id !== (int) $item->id) {
                throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
            }
            $quantity = InventoryQuantity::parse($request->quantity, $item->tracking_mode);
            $links = $this->repository->receiptLinks($tenant, $lots->pluck('id')->all(), true);
            $receiptUsed = $lotUsed = 0;
            foreach ($links as $link) {
                if ($link->purchase_receipt_line_id === $receipt->id) $receiptUsed += InventoryQuantity::parseBalance($link->linked_quantity);
                if ($link->acquisition_lot_id === $lot->id) $lotUsed += InventoryQuantity::parseBalance($link->linked_quantity);
            }
            if ($receiptUsed + $quantity > InventoryQuantity::parse($receipt->received_quantity)
                || $lotUsed + $quantity > InventoryQuantity::parse($lot->acquired_quantity)) {
                throw new InvalidTenantRequest(__('ownership.receipt_link_exceeded'));
            }
            $members = $this->repository->currentMemberships($tenant, $lots->pluck('id')->all(), true, ownedItemId: (int) $owner->id);
            $stock = $this->inventoryService->ownershipStock($item, pieceCodes: $request->serializedItemCodes);
            $this->validatePieces($item->tracking_mode, $quantity, $request->serializedItemCodes,
                $receipt->serialized_item_codes ?? [], $stock->pieces, $members);
            $link = $this->repository->createReceiptLink(['tenant_id' => $tenant, 'code' => $this->codes->generate('ownership_receipt_links', CarbonImmutable::now()),
                'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id, 'purchase_receipt_line_id' => $receipt->id,
                'linked_quantity' => $request->quantity, 'remaining_owned_quantity' => $request->quantity, 'idempotency_record_id' => $record->id]);
            foreach ($stock->pieces as $piece) {
                $this->repository->createMembership(['tenant_id' => $tenant, 'owned_item_id' => $owner->id,
                    'acquisition_lot_id' => $lot->id, 'ownership_receipt_link_id' => $link->id,
                    'inventory_serialized_item_id' => $piece['id'], 'started_at' => CarbonImmutable::now('UTC')]);
            }
            $result = new OwnershipResult($owner->code, $this->repository->advanceVersion($owner),
                [$lot->code], [], [$link->code], $request->serializedItemCodes);
            $this->recordAudit('ownership.receipt_linked', get_class($owner), (int) $owner->id, $result->toArray());
            $this->idempotency->markCompleted($record, 200, $result->toArray());
            return $result;
        });
    }

    private function validatePieces(string $mode, int $quantity, array $codes, array $receiptCodes, array $pieces, $members): void
    {
        // Linking retains exact receipt provenance and rejects existing current Ownership memberships.
        if ($mode !== 'SERIALIZED') {
            if ($codes !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            return;
        }
        if (count($codes) * 1000 !== $quantity || count($pieces) !== count($codes)
            || array_diff($codes, $receiptCodes) !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
        $owned = $members->keyBy('inventory_serialized_item_id');
        foreach ($pieces as $piece) {
            if ($piece['location_id'] === null) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            if ($owned->has($piece['id'])) throw new InvalidTenantRequest(__('ownership.piece_already_owned'));
        }
    }
}
