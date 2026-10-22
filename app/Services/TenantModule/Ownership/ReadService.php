<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Public\RequestObjects\{OwnershipListFilter, OwnershipLotFilter, OwnershipMovementFilter, OwnershipCandidateFilter};
use App\DTOs\Ownership\Public\ResponseObjects\{OwnershipItemSummary, OwnershipItemPage, OwnershipItemDetail, OwnershipLot, OwnershipLotPage, OwnershipMovementRow, OwnershipMovementPage, OwnershipReceiptCandidate, OwnershipReceiptPage, OwnershipPiece, OwnershipPiecePage};
use App\DataObjects\RequestObjects\InventorySerializedItemFilter;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\InventoryService;
use Carbon\CarbonImmutable;
use Brick\Math\BigDecimal;

/** Bounded scalar projections with batched facts; serialization never triggers a query. */
class ReadService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private InventoryService $inventoryService,
        private LookupService $lookup, private PurchaseSourceService $purchases) {}

    public function itemPage(OwnershipListFilter $filter, ?string $ownedCode = null): OwnershipItemPage
    {
        // Resolve the page first; every following batch is limited to the selected item IDs.
        $tenant = $this->resolveCurrentTenantId();
        $page = $this->repository->itemPage($tenant, $filter, CarbonImmutable::now('UTC')->toDateTimeString(), $ownedCode);
        $rows = collect($page->items());
        $ids = $rows->pluck('id')->all();
        $inventory = $this->inventoryService->summariesByCodes($rows->pluck('inventory_item_code')->all());
        $facts = $this->repository->lotFacts($tenant, $ids);
        $receipts = $this->purchases->itemReceipts($ids);
        $items = $rows->map(function ($row) use ($inventory, $facts, $receipts): OwnershipItemSummary {
            $physical = $inventory->get($row->inventory_item_code);
            $lot = $facts->get($row->id);
            $received = $receipts->get($row->id)?->quantity ?? '0.000';
            return new OwnershipItemSummary(code: $row->code, inventoryItemCode: $row->inventory_item_code,
                catalogItemCode: $row->catalog_item_code, name: $row->name, description: $row->description,
                trackingMode: $row->tracking_mode, unitCode: $row->unit_code, unitName: $row->unit_name,
                quantityScale: (string) $row->quantity_scale, ownedQuantity: $row->owned,
                reservedQuantity: $row->reserved, pledgedQuantity: $row->pledged, availableQuantity: $row->available,
                physicalQuantity: $physical->totalQuantity, receivedBackedQuantity: (string) ($lot?->received ?? '0.000'),
                awaitingReceiptQuantity: $this->difference($lot?->acquired ?? '0.000', $received),
                pendingLinkQuantity: $this->difference($received, $lot?->linked ?? '0.000'), lotCount: (int) ($lot?->lot_count ?? 0),
                lifecycleStatus: $row->lifecycle_status, updateKey: (int) $row->update_key,
                imageUrl: $physical->imageUrl, imageUrlExpiresAt: $physical->imageUrlExpiresAt);
        })->all();
        return OwnershipItemPage::fromPaginator($page, $items);
    }

    public function detail(string $code): OwnershipItemDetail
    {
        // Detail never embeds all lots or pieces; each has its own bounded contract.
        $this->lookup->byCode($code);
        $summary = $this->itemPage(new OwnershipListFilter(), $code)->items[0];
        $physical = $this->inventoryService->itemDetail($summary->inventoryItemCode);
        return new OwnershipItemDetail($summary, $physical->locations,
            $summary->pendingLinkQuantity === '0.000' ? 'LINKED' : 'PENDING');
    }

    public function lotPage(string $code, OwnershipLotFilter $filter): OwnershipLotPage
    {
        // Page lots separately and resolve all receipt quantities for this bounded page in batches.
        $owner = $this->lookup->byCode($code);
        $tenant = $this->resolveCurrentTenantId();
        $page = $this->repository->lotPage($tenant, (int) $owner->id, $filter, CarbonImmutable::now('UTC')->toDateTimeString());
        $rows = collect($page->items());
        $facts = $this->repository->receiptFacts($tenant, $rows->pluck('id')->all());
        $receipts = $this->purchases->quantities($rows->where('source_module', 'PURCHASING')->where('source_type', 'PurchaseOrderLine')->pluck('source_code')->all());
        $items = $rows->map(function ($row) use ($facts, $receipts): OwnershipLot {
            $fact = $facts->get($row->id);
            $received = $receipts->get($row->source_code)?->quantity ?? '0.000';
            return new OwnershipLot(code: $row->code, sourceModule: $row->source_module, sourceType: $row->source_type,
                sourceCode: $row->source_code, acquiredAt: $row->acquired_at, acquiredQuantity: $row->acquired_quantity,
                ownedQuantity: $row->owned, reservedQuantity: $row->reserved, pledgedQuantity: $row->pledged,
                availableQuantity: $row->available, receivedBackedQuantity: $fact?->received ?? '0.000',
                awaitingReceiptQuantity: $this->difference($row->acquired_quantity, $received),
                pendingLinkQuantity: $this->difference($received, $fact?->linked ?? '0.000'),
                unitCostBasis: $row->unit_cost_basis, estimatedUnitValue: $row->estimated_unit_value,
                currencyCode: $row->currency_code, description: $row->description);
        })->all();
        return OwnershipLotPage::fromPaginator($page, $items);
    }

    public function movementPage(string $code, OwnershipMovementFilter $filter): OwnershipMovementPage
    {
        // Occurrence date plus ID provides stable newest-first order; recording time stays distinct.
        $owner = $this->lookup->byCode($code);
        $page = $this->repository->movementPage($this->resolveCurrentTenantId(), (int) $owner->id, $filter);
        $items = collect($page->items())->map(fn ($row) => new OwnershipMovementRow(
            $row->code, $row->lot_code, $row->movement_type, $row->quantity, $row->owned_delta, $row->reserved_delta,
            $row->pledged_delta, $row->source_module, $row->source_type, $row->source_code,
            CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
            $row->created_at === null ? null : CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String()))->all();
        return OwnershipMovementPage::fromPaginator($page, $items);
    }

    public function candidates(string $code, string $lotCode, OwnershipCandidateFilter $filter): OwnershipReceiptPage|OwnershipPiecePage
    {
        // Candidate input identifies one tenant/item/source-qualified lot, not arbitrary receipts.
        $owner = $this->lookup->byCode($code);
        $tenant = $this->resolveCurrentTenantId();
        $lot = $this->repository->lotByCode($tenant, (int) $owner->id, $lotCode)
            ?? throw new TenantNotFound(__('ownership.lot_not_found'));
        if ($lot->source_module !== 'PURCHASING' || $lot->source_type !== 'PurchaseOrderLine') {
            throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
        }
        if ($filter->purchaseReceiptLineCode !== null) return $this->pieces($owner, $lot, $filter);
        $page = $this->purchases->page($lot->source_code, $filter);
        $allocated = $this->repository->receiptAllocatedTotals($tenant, collect($page->items())->pluck('id')->all());
        $items = collect($page->items())->map(function ($row) use ($allocated, $owner): OwnershipReceiptCandidate {
            $linked = $allocated->get($row->id)?->linked ?? '0.000';
            return new OwnershipReceiptCandidate($row->code, $row->orderLine->code, $row->receipt->received_at->toDateString(),
                $owner->inventoryItem->code, $row->received_quantity, $linked, $this->difference($row->received_quantity, $linked));
        })->all();
        return OwnershipReceiptPage::fromPaginator($page, $items);
    }

    private function pieces($owner, $lot, OwnershipCandidateFilter $filter): OwnershipPiecePage
    {
        // Restrict serial candidates to the selected receipt before paging them.
        $receipt = $this->purchases->receipt($filter->purchaseReceiptLineCode);
        if ($receipt->orderLine->code !== $lot->source_code || (int) $receipt->inventory_item_id !== (int) $owner->inventory_item_id) {
            throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
        }
        $page = $this->inventoryService->receiptPiecePage($owner->inventoryItem->code, $receipt->serialized_item_codes ?? [],
            new InventorySerializedItemFilter(page: $filter->page, perPage: $filter->perPage, search: $filter->search));
        $linked = $this->repository->linkedPieceIds($this->resolveCurrentTenantId(), collect($page->items())->pluck('code')->all());
        $items = collect($page->items())->map(fn ($piece) => new OwnershipPiece($piece->code, $piece->identifier, $receipt->code,
            $piece->location?->code, $piece->location?->name, $linked->has($piece->code) ? 'LINKED' : 'UNLINKED',
            ! $linked->has($piece->code) && $piece->isAvailable && ($piece->location?->isActive ?? false)))->all();
        return OwnershipPiecePage::fromPaginator($page, $items);
    }

    private function difference(string $first, string $second): string
    {
        // The installed decimal library handles aggregate values beyond a single-operation quantity bound.
        $difference = BigDecimal::of($first)->minus($second);
        return $difference->isNegative() ? '0.000' : (string) $difference->toScale(3);
    }
}
